<?php

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\Sound;
use App\Services\AI\AiException;
use App\Services\AI\SoundSuggester;
use App\Support\AutoTags;
use App\Support\Suggestions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * NO `use Throwable;` HERE, and that is not an oversight.
 *
 * A single-file Livewire component is compiled into the GLOBAL namespace, and
 * an import of a non-compound name in the global namespace imports a class
 * onto itself: PHP raises "the use statement has no effect", Laravel turns
 * that warning into an ErrorException, and the screen dies before it renders.
 * Every other import in this file is namespaced, so only this one bites.
 *
 * Root-namespace classes are written with a leading backslash instead —
 * \Throwable below.
 */

new #[Layout('layouts.admin')] #[Title('Suggestions')] class extends Component {
    /** @var array<string, string> */
    public array $values = [];

    /**
     * The last test result per provider.
     *
     * Kept per provider rather than as one slot, because the entire reason
     * both drivers exist is to put their answers next to each other. One slot
     * would mean the second test erases the thing being compared against.
     *
     * NEVER holds a key — only what came back.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $tests = [];

    /**
     * The model ids each provider says this key may use.
     *
     * Public because the chips are rendered from it. Contains ids only —
     * nothing here came from a key beyond the fact that one worked.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $models = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $stored = Setting::cached();

        foreach (Suggestions::FIELDS as $field => $meta) {
            /*
             * A SECRET IS NEVER LOADED. Not even to be re-saved unchanged.
             *
             * A Livewire public property is serialised into the page: the
             * snapshot is in the HTML, readable in view-source, and present in
             * any screenshot of this screen. A secret that goes into $values
             * has left the server, and no amount of type="password" on the
             * input takes it back.
             *
             * The box always starts empty and means "the new one". Empty on
             * save means "leave what is stored alone".
             */
            if ($meta['type'] === 'secret') {
                $this->values[$field] = '';

                continue;
            }

            $this->values[$field] = (string) ($stored[$meta['key']] ?? '');
        }
    }

    public function save(): void
    {
        $this->validate($this->rules());

        $before = Setting::cached();
        $changed = 0;

        foreach (Suggestions::FIELDS as $field => $meta) {
            $new = trim((string) ($this->values[$field] ?? ''));

            if ($meta['type'] === 'secret') {
                if ($new === '') {
                    continue;   // nothing typed: keep what is stored
                }

                Setting::put($meta['key'], $new, 'suggestions', encrypted: true);

                // The VALUE is never logged. ActivityLog::scrub would catch a
                // field called "key" anyway, but not relying on that is
                // cheaper than finding out it missed one.
                ActivityLog::record('settings.updated', null,
                    'Suggestions — '.$meta['label'].' replaced',
                    ['setting' => $meta['key']]);

                $this->values[$field] = '';
                $changed++;

                continue;
            }

            $old = (string) ($before[$meta['key']] ?? '');

            // Choosing what the default already says stores nothing: a row
            // that changes no behaviour is a row somebody later has to work
            // out the meaning of.
            if ($new === Suggestions::defaultValue($field)) {
                $new = '';
            }

            if ($new === $old) {
                continue;
            }

            Setting::put($meta['key'], $new === '' ? null : $new, 'suggestions');

            ActivityLog::record('settings.updated', null,
                'Suggestions — '.$meta['label'].($new === '' ? ' reset to the default' : ' changed'),
                ['setting' => $meta['key'], 'from' => $old ?: null, 'to' => $new ?: null]);

            $changed++;
        }

        $this->dispatch('saved');

        session()->flash('ok', match ($changed) {
            0 => 'Nothing had changed.',
            1 => 'Saved.',
            default => "Saved — {$changed} changes.",
        });
    }

    /** Delete a stored key outright, falling back to whatever .env says. */
    public function forgetSecret(string $field): void
    {
        if ((Suggestions::FIELDS[$field]['type'] ?? '') !== 'secret') {
            return;
        }

        Setting::put(Suggestions::FIELDS[$field]['key'], null, 'suggestions');

        ActivityLog::record('settings.updated', null,
            'Suggestions — '.Suggestions::FIELDS[$field]['label'].' removed',
            ['setting' => Suggestions::FIELDS[$field]['key']]);

        session()->flash('ok', Suggestions::FIELDS[$field]['label'].' removed.');
    }

    /**
     * Send one real sound through one provider and print what came back.
     *
     * ── WHY A REAL SOUND AND NOT A PING ──────────────────────────────────
     *
     * A green tick that only proves the key authenticates answers the least
     * interesting question. What has to be decided on this screen is whether
     * the tags are any good for THIS catalogue — and a made-up example sound
     * would be answered well by both, because an invented filename is always
     * a tidy one. The filenames that separate the two providers are the real
     * ones, with the underscores and the takes numbered 03.
     *
     * It runs in the request rather than the queue, on purpose: the answer is
     * what is being looked at, and a test whose result appears somewhere else
     * a minute later is a test nobody runs twice.
     */
    public function test(string $provider): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        if (! array_key_exists($provider, Suggestions::PROVIDERS)) {
            return;
        }

        $sound = $this->sampleSound();

        if (! $sound) {
            $this->tests[$provider] = ['error' => 'There is no sound in the catalogue to test with.'];

            return;
        }

        if (! Suggestions::ready($provider)) {
            $this->tests[$provider] = ['error' => 'No API key — neither stored here nor in .env.'];

            return;
        }

        $started = microtime(true);

        try {
            $answer = (new SoundSuggester(SoundSuggester::driver($provider)))->for($sound);
        } catch (AiException $e) {
            $this->tests[$provider] = [
                'error' => $e->userMessage(),
                'detail' => $e->getMessage(),
            ];

            return;
        } catch (\Throwable $e) {
            // Deliberately shown rather than swallowed. This screen exists to
            // find out why something is not working; a generic "failed" here
            // would send the answer to the log file instead of to the person
            // who is looking straight at it.
            $this->tests[$provider] = [
                'error' => 'Something went wrong before the answer could be read.',
                'detail' => $e->getMessage(),
            ];

            return;
        }

        $this->tests[$provider] = [
            'tags' => $answer['tags'],
            'search_terms' => $answer['search_terms'] ?? [],
            'category' => $answer['category'],
            'meta_description' => $answer['meta_description'],
            'model' => $answer['model'],
            'ms' => (int) round((microtime(true) - $started) * 1000),
            'sound_id' => $sound->id,
            'sound' => $sound->title,
            'filename' => $sound->original_filename ?: $sound->title,
            'saved' => false,
        ];
    }

    /**
     * Keep what the test just produced.
     *
     * ── WHY A TEST NEEDS A SAVE BUTTON ───────────────────────────────────
     *
     * Without one, pressing Try spends a real API call, prints a real answer
     * for a real sound in the catalogue, and then throws it away. That is
     * confusing before it is wasteful: the first honest question anybody asks
     * looking at the result is "so where did that go?", and the answer was
     * "nowhere", which makes the whole screen feel like a demo of itself.
     *
     * Saving here does exactly what the queued job does — stores the
     * suggestion for review and writes the tags — so the sound ends up in the
     * same state whether the answer arrived from this button or from the
     * worker. Two paths that leave different states is a bug waiting for a
     * quiet afternoon.
     */
    public function keepTest(string $provider): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $result = $this->tests[$provider] ?? null;

        if (! $result || isset($result['error']) || empty($result['sound_id'])) {
            return;
        }

        $sound = Sound::find($result['sound_id']);

        if (! $sound) {
            return;
        }

        $sound->forceFill([
            'ai_suggestions' => [
                'tags' => $result['tags'],
                'search_terms' => $result['search_terms'] ?? [],
                'category' => $result['category'],
                'meta_description' => $result['meta_description'],
                'provider' => $provider,
                'model' => $result['model'],
            ],
            'ai_suggested_at' => now(),
            'ai_provider' => $provider,
            'search_terms' => $result['search_terms'] ?? [],
        ])->save();

        $names = $result['tags'];

        if (count($names) < AutoTags::MINIMUM) {
            $names = array_merge($names, AutoTags::forSound($sound));
        }

        $added = AutoTags::attach($sound, $names);

        $this->tests[$provider]['saved'] = true;

        session()->flash('ok', "Saved to “{$sound->title}” — {$added} tags written. "
            .'The description is waiting in In review.');
    }

    /**
     * Ask the provider which models THIS key may use.
     *
     * ── WHY THIS IS A BUTTON AND NOT A LINK TO THE DOCUMENTATION ─────────
     *
     * The field wants an exact id, and the documentation lists the ids that
     * exist — which is not the same set as the ids a given key is allowed to
     * call, and not the same set as the ones that still answer. Typing one
     * from a blog post and finding out at the first upload is a slow way to
     * learn that a name changed. The provider knows; asking it is one call.
     *
     * The ids come back as clickable chips that fill the field in, because
     * the whole failure being designed against here is a typo in a string
     * nobody can check by eye.
     */
    public function loadModels(string $provider): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        if (! array_key_exists($provider, Suggestions::PROVIDERS)) {
            return;
        }

        $key = Suggestions::key($provider);

        if (! $key) {
            $this->models[$provider] = ['error' => 'No API key — save one first, here or in .env.'];

            return;
        }

        try {
            $response = $provider === 'gemini'
                ? Http::withHeaders(['x-goog-api-key' => $key])
                    ->timeout(20)
                    ->get('https://generativelanguage.googleapis.com/v1beta/models', ['pageSize' => 200])
                : Http::withToken($key)
                    ->timeout(20)
                    ->get('https://api.openai.com/v1/models');
        } catch (\Throwable $e) {
            $this->models[$provider] = [
                'error' => 'The provider could not be reached.',
                'detail' => $e->getMessage(),
            ];

            return;
        }

        if ($response->failed()) {
            $this->models[$provider] = [
                'error' => $response->status() === 401 || $response->status() === 403
                    ? 'The API key was rejected.'
                    : 'The provider refused the request ('.$response->status().').',
                'detail' => (string) ($response->json('error.message') ?? ''),
            ];

            return;
        }

        $ids = $provider === 'gemini'
            /*
             * Gemini returns every model including embedding and image ones.
             * supportedGenerationMethods is the provider's own answer to "can
             * this thing do what we are about to ask it", so it is used
             * instead of guessing from the name.
             */
            ? collect($response->json('models', []))
                ->filter(fn ($m) => in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true))
                ->map(fn ($m) => Str::after((string) ($m['name'] ?? ''), 'models/'))
            /*
             * OpenAI gives no such field, so this is a filter by name and
             * therefore a guess — a deliberately loose one. Anything it
             * wrongly hides can still be typed into the box by hand.
             */
            : collect($response->json('data', []))
                ->pluck('id')
                ->filter(fn ($id) => is_string($id) && str_starts_with($id, 'gpt-'))
                ->reject(fn ($id) => Str::contains($id, ['audio', 'realtime', 'image', 'tts', 'transcribe', 'search', 'moderation']));

        $this->models[$provider] = [
            'ids' => $ids->filter()->unique()->sort()->values()->all(),
        ];
    }

    /** Put a listed id into the field. */
    public function useModel(string $provider, string $id): void
    {
        $field = $provider.'_model';

        if (array_key_exists($field, Suggestions::FIELDS)) {
            $this->values[$field] = $id;
        }
    }

    /**
     * Which sound gets used for a test.
     *
     * The newest one with a real filename kept, because those are the imports
     * that will actually go through the suggester. Falls back to the newest
     * sound of any kind so the button still works on a catalogue imported
     * before original_filename existed.
     */
    private function sampleSound(): ?Sound
    {
        return Sound::with('category')
            ->whereNotNull('original_filename')
            ->latest('id')
            ->first()
            ?? Sound::with('category')->latest('id')->first();
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array
    {
        $rules = [];

        foreach (Suggestions::FIELDS as $field => $meta) {
            $rules["values.{$field}"] = match ($meta['type']) {
                'select' => ['nullable', Rule::in(array_keys($meta['options']))],
                'secret' => ['nullable', 'string', 'max:300'],
                default => ['nullable', 'string', 'max:100'],
            };
        }

        return $rules;
    }

    /* ═════════════════════════════ Helpers ═════════════════════════════ */

    #[Computed]
    public function ready(): bool
    {
        return Schema::hasTable('settings');
    }

    /**
     * How many sounds have never been sent to a model.
     *
     * Shown next to the test result, because that is the moment the decision
     * gets made: you have just read one answer and you either trust it for
     * the catalogue or you do not. A number there turns "it works" into "it
     * works, and there are 98 more waiting".
     *
     * The batch itself lives in In review and is NOT duplicated here. Queueing
     * a hundred sounds from the settings screen would put the start of the
     * work in one place and the approving of it in another, and the second
     * time somebody pressed it they would not remember which screen showed
     * the result.
     */
    #[Computed]
    public function pendingCount(): int
    {
        return Sound::query()
            ->where('status', '!=', Sound::STATUS_REJECTED)
            ->whereNull('ai_suggested_at')
            ->count();
    }

    /**
     * What the suggester is actually doing, in one sentence.
     *
     * Same verdict pattern as the captcha strip on the Security screen, for
     * the same reason: four settings decide whether anything happens at all,
     * and without one line naming the condition that caught you, "I pasted
     * the key and nothing happened" is a guessing game.
     */
    #[Computed]
    public function state(): array
    {
        $provider = Suggestions::provider();
        $name = Suggestions::PROVIDERS[$provider];
        $source = Suggestions::keySource($provider);

        if ($source === 'none') {
            return [
                'live' => false,
                'why' => $name.' is selected, but it has no API key — not here and not in .env. Nothing is being suggested.',
            ];
        }

        return [
            'live' => true,
            'why' => $name.' answers, using '.Suggestions::model($provider).'. The key in effect is the one '
                .($source === 'settings' ? 'stored on this screen' : 'in .env')
                .'.',
        ];
    }

    public function keySource(string $provider): string
    {
        return Suggestions::keySource($provider);
    }

    public function fallback(string $field): string
    {
        return (string) config('dbelo.'.Suggestions::FIELDS[$field]['key']);
    }

    public function hasSecret(string $field): bool
    {
        return Suggestions::hasSecret($field);
    }
}; ?>

<div class="space-y-5">

    @if (! $this->ready)
        <x-admin.migration-pending what="Suggestion settings" table="settings" />
    @else

        @if (session('ok'))
            <div class="rounded-2xl border border-success/25 bg-success/[0.06] px-5 py-3.5 text-[0.86rem] text-paper/75">
                <x-icon name="circle-check" style="solid" class="mr-1.5 text-[0.8rem] text-success" />
                {{ session('ok') }}
            </div>
        @endif

        {{-- ══════════════════════════════════════════════════════════════
             WHAT THIS SCREEN IS, AND WHAT IT NEVER DOES
             ══════════════════════════════════════════════════════════════ --}}
        <div class="rounded-2xl border border-hairline bg-panel px-5 py-4">
            <p class="max-w-[84ch] text-[0.82rem] leading-relaxed text-paper/45">
                <x-icon name="wand-magic-sparkles" style="solid" class="mr-1.5 text-[0.75rem] text-info" />
                When a sound is uploaded, a model is asked for tags, a category and a meta description.
                <strong class="text-paper/70">Nothing it says is ever written to a sound.</strong>
                The answers wait in
                <a href="{{ route('moderate') }}" wire:navigate class="underline underline-offset-2 hover:text-paper">In review</a>
                until you accept or edit them. The model has not heard the audio — it is reading a filename and a
                duration — so it is a first draft, not a source.
            </p>
        </div>

        {{-- ─────────────────── The one-line verdict ─────────────────── --}}
        @php($state = $this->state)

        <div @class([
            'flex items-start gap-3 rounded-2xl border px-5 py-4',
            'border-success/25 bg-success/[0.06]' => $state['live'],
            'border-hairline bg-panel' => ! $state['live'],
        ])>
            <x-icon :name="$state['live'] ? 'circle-check' : 'circle-pause'" style="solid"
                    class="mt-[0.15rem] shrink-0 text-[0.8rem] {{ $state['live'] ? 'text-success' : 'text-paper/30' }}" />

            <div class="min-w-0">
                <div class="text-[0.86rem] text-paper/80">
                    {{ $state['live'] ? 'Suggestions are on.' : 'Nothing is being suggested.' }}
                </div>
                <p class="mt-1 max-w-[74ch] text-[0.8rem] leading-relaxed text-paper/45">{{ $state['why'] }}</p>
            </div>
        </div>

        <form wire:submit="save"
              x-data="{ dirty: false }"
              x-on:input="dirty = true"
              x-on:change="dirty = true"
              x-on:saved.window="dirty = false"
              class="space-y-5 pb-24">

            @foreach (\App\Support\Suggestions::SECTIONS as $sectionKey => $section)
                <div class="rounded-2xl border border-hairline bg-panel" wire:key="sec-{{ $sectionKey }}">
                    <div class="border-b border-hairline px-5 py-4">
                        <h2 class="text-[0.95rem] font-medium">{{ $section['label'] }}</h2>
                        @if ($section['note'])
                            <p class="mt-0.5 max-w-[76ch] text-[0.78rem] leading-relaxed text-paper/40">{{ $section['note'] }}</p>
                        @endif
                    </div>

                    {{-- Where the key in effect came from, per provider. Two
                         places can hold it, and a screen that will not say
                         which one is winning is a screen that lets you edit
                         the wrong one for twenty minutes. --}}
                    @if (in_array($sectionKey, ['gemini', 'openai'], true))
                        @php($source = $this->keySource($sectionKey))

                        <div class="border-b border-hairline px-5 py-3">
                            <div class="flex flex-wrap items-center gap-2 text-[0.8rem]">
                                <x-icon :name="$source === 'none' ? 'key-skeleton' : 'key'" style="solid"
                                        class="text-[0.72rem] {{ $source === 'none' ? 'text-paper/25' : 'text-success' }}" />

                                <span class="text-paper/45">
                                    @switch($source)
                                        @case('settings') The key in effect is the one <strong class="text-paper/70">stored here</strong>. @break
                                        @case('env') No key is stored here, so the one in <code class="font-mono text-paper/70">.env</code> is in effect. @break
                                        @default No key at all — this provider cannot answer. @break
                                    @endswitch
                                </span>
                            </div>
                        </div>
                    @endif

                    <div class="divide-y divide-hairline">
                        @foreach ($section['fields'] as $field)
                            @php($meta = \App\Support\Suggestions::FIELDS[$field])

                            <div wire:key="f-{{ $field }}">
                                <x-admin.setting-field
                                    :label="$meta['label']"
                                    :name="'values.'.$field"
                                    :affects="$meta['help'] ?: null">

                                    @switch($meta['type'])

                                        @case('secret')
                                            @if ($this->hasSecret($field))
                                                <div class="flex flex-wrap items-center gap-3">
                                                    <span class="inline-flex items-center gap-2 rounded-lg bg-success/10 px-3 py-2 text-[0.8rem] text-success">
                                                        <x-icon name="lock" style="solid" class="text-[0.7rem]" />
                                                        Saved and encrypted
                                                    </span>

                                                    <button type="button" wire:click="forgetSecret('{{ $field }}')"
                                                            class="text-[0.78rem] text-paper/35 underline-offset-2 transition hover:text-danger hover:underline">
                                                        Remove
                                                    </button>
                                                </div>

                                                <input type="password" wire:model="values.{{ $field }}" autocomplete="new-password"
                                                       placeholder="Type a new one to replace it"
                                                       class="mt-2.5 w-full rounded-lg border-0 bg-raised px-3 py-2.5 font-mono text-[0.8rem] focus:outline-none focus:ring-1 focus:ring-brand" />
                                            @else
                                                <input type="password" wire:model="values.{{ $field }}" autocomplete="new-password"
                                                       placeholder="Not set"
                                                       class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 font-mono text-[0.8rem] focus:outline-none focus:ring-1 focus:ring-brand" />
                                            @endif
                                            @break

                                        @case('select')
                                            <select wire:model.live="values.{{ $field }}"
                                                    class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand">
                                                <option value="">Default — {{ $meta['options'][$this->fallback($field)] ?? $this->fallback($field) }}</option>

                                                @foreach ($meta['options'] as $value => $caption)
                                                    <option value="{{ $value }}" wire:key="{{ $field }}-{{ $value }}">{{ $caption }}</option>
                                                @endforeach
                                            </select>
                                            @break

                                        @default
                                            <input type="text" wire:model.live.debounce.500ms="values.{{ $field }}" maxlength="100"
                                                   autocomplete="off" spellcheck="false"
                                                   placeholder="{{ $this->fallback($field) }}"
                                                   class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 font-mono text-[0.8rem] focus:outline-none focus:ring-1 focus:ring-brand" />
                                    @endswitch
                                </x-admin.setting-field>
                            </div>
                        @endforeach
                    </div>

                    {{-- ─────────── Which ids this key may use ─────────── --}}
                    @if (in_array($sectionKey, ['gemini', 'openai'], true))
                        <div class="border-t border-hairline px-5 py-4">
                            <div class="flex flex-wrap items-center gap-3">
                                <button type="button" wire:click="loadModels('{{ $sectionKey }}')"
                                        wire:loading.attr="disabled" wire:target="loadModels('{{ $sectionKey }}')"
                                        class="inline-flex items-center gap-2 rounded-xl bg-raised px-4 py-2 text-[0.82rem] text-paper/80 transition hover:bg-rail disabled:opacity-50">
                                    <x-icon name="list" style="solid" class="text-[0.75rem] text-info"
                                            wire:loading.remove wire:target="loadModels('{{ $sectionKey }}')" />
                                    <x-icon name="spinner-third" style="solid" class="animate-spin text-[0.75rem] text-info"
                                            wire:loading wire:target="loadModels('{{ $sectionKey }}')" />
                                    See available models
                                </button>

                                <span class="text-[0.78rem] text-paper/30">Asks the provider what this key may call.</span>
                            </div>

                            @php($listing = $models[$sectionKey] ?? null)

                            @if ($listing)
                                @if (isset($listing['error']))
                                    <div class="mt-3 rounded-xl bg-danger/[0.08] px-4 py-3">
                                        <div class="text-[0.86rem] text-paper/80">{{ $listing['error'] }}</div>
                                        @if (! empty($listing['detail']))
                                            <p class="mt-1.5 break-words font-mono text-[0.74rem] leading-relaxed text-paper/40">{{ $listing['detail'] }}</p>
                                        @endif
                                    </div>
                                @elseif (empty($listing['ids']))
                                    <p class="mt-3 text-[0.82rem] text-paper/40">The provider returned no model this key can use for text.</p>
                                @else
                                    <div class="mt-3 rounded-xl bg-raised px-4 py-4">
                                        <p class="text-[0.78rem] text-paper/35">
                                            {{ count($listing['ids']) }} available. Click one to put it in the field above.
                                            An id ending in <code class="font-mono">-latest</code> is an alias the provider
                                            re-points without telling you, so descriptions written after that day come from a
                                            different model — prefer an exact one.
                                        </p>

                                        <div class="mt-3 flex flex-wrap gap-1.5">
                                            @foreach ($listing['ids'] as $id)
                                                @php($isAlias = str_ends_with($id, '-latest') || str_contains($id, 'preview'))
                                                @php($inUse = ($values[$sectionKey.'_model'] ?? '') === $id)

                                                <button type="button" wire:key="m-{{ $sectionKey }}-{{ $id }}"
                                                        wire:click="useModel('{{ $sectionKey }}', '{{ $id }}')"
                                                        x-on:click="dirty = true"
                                                        @class([
                                                            'rounded-lg px-2.5 py-1 font-mono text-[0.74rem] transition',
                                                            'bg-brand text-white' => $inUse,
                                                            'bg-warning/10 text-warning hover:bg-warning/20' => ! $inUse && $isAlias,
                                                            'bg-rail text-paper/70 hover:bg-rail hover:text-paper' => ! $inUse && ! $isAlias,
                                                        ])>{{ $id }}</button>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            @endif
                        </div>
                    @endif

                    {{-- ─────────── Try it on a real sound ─────────── --}}
                    @if (in_array($sectionKey, ['gemini', 'openai'], true))
                        <div class="border-t border-hairline px-5 py-4">
                            <div class="flex flex-wrap items-center gap-3">
                                <button type="button" wire:click="test('{{ $sectionKey }}')"
                                        wire:loading.attr="disabled" wire:target="test('{{ $sectionKey }}')"
                                        class="inline-flex items-center gap-2 rounded-xl bg-raised px-4 py-2 text-[0.82rem] text-paper/80 transition hover:bg-rail disabled:opacity-50">
                                    <x-icon name="flask" style="solid" class="text-[0.75rem] text-info"
                                            wire:loading.remove wire:target="test('{{ $sectionKey }}')" />
                                    <x-icon name="spinner-third" style="solid" class="animate-spin text-[0.75rem] text-info"
                                            wire:loading wire:target="test('{{ $sectionKey }}')" />
                                    Try it on a real sound
                                </button>

                                <span class="text-[0.78rem] text-paper/30">
                                    One real sound from your catalogue, through the key that is <em>saved</em> —
                                    not what is typed above. Nothing is written unless you say so.
                                </span>
                            </div>

                            @php($result = $tests[$sectionKey] ?? null)

                            @if ($result)
                                @if (isset($result['error']))
                                    <div class="mt-3 rounded-xl bg-danger/[0.08] px-4 py-3">
                                        <div class="flex items-start gap-3">
                                            <x-icon name="circle-exclamation" style="solid" class="mt-[0.15rem] shrink-0 text-[0.8rem] text-danger" />
                                            <div class="min-w-0">
                                                <div class="text-[0.86rem] text-paper/80">{{ $result['error'] }}</div>
                                                @if (! empty($result['detail']))
                                                    <p class="mt-1.5 break-words font-mono text-[0.74rem] leading-relaxed text-paper/40">{{ $result['detail'] }}</p>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @else
                                    <div class="mt-3 space-y-3 rounded-xl bg-raised px-4 py-4">
                                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-[0.76rem] text-paper/35">
                                            <span class="font-mono text-paper/55">{{ $result['filename'] }}</span>
                                            <span>·</span>
                                            <span>{{ $result['model'] }}</span>
                                            <span>·</span>
                                            <span>{{ number_format($result['ms'] / 1000, 1) }}s</span>
                                        </div>

                                        <div>
                                            <div class="text-[0.68rem] uppercase tracking-[0.14em] text-paper/30">Tags</div>
                                            <div class="mt-1.5 flex flex-wrap gap-1.5">
                                                @forelse ($result['tags'] as $tag)
                                                    <span class="rounded-lg bg-rail px-2.5 py-1 text-[0.76rem] text-paper/70">{{ $tag }}</span>
                                                @empty
                                                    <span class="text-[0.8rem] text-paper/35">None came back.</span>
                                                @endforelse
                                            </div>
                                        </div>

                                        {{-- The Spanish terms. Shown here and
                                             nowhere else on the site: their
                                             whole job is to be matched by a
                                             search, and this is the one place
                                             where somebody needs to check they
                                             are not nonsense. --}}
                                        @if (! empty($result['search_terms']))
                                            <div>
                                                <div class="text-[0.68rem] uppercase tracking-[0.14em] text-paper/30">
                                                    Spanish search terms
                                                    <span class="ml-1 normal-case tracking-normal text-paper/25">never shown on the site</span>
                                                </div>
                                                <div class="mt-1.5 flex flex-wrap gap-1.5">
                                                    @foreach ($result['search_terms'] as $term)
                                                        <span class="rounded-lg bg-info/10 px-2.5 py-1 text-[0.76rem] text-info">{{ $term }}</span>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif

                                        <div class="grid gap-3 sm:grid-cols-[9rem_1fr]">
                                            <div>
                                                <div class="text-[0.68rem] uppercase tracking-[0.14em] text-paper/30">Category</div>
                                                <div class="mt-1 text-[0.82rem] text-paper/70">{{ $result['category'] ?: '—' }}</div>
                                            </div>

                                            <div>
                                                <div class="text-[0.68rem] uppercase tracking-[0.14em] text-paper/30">
                                                    Meta description
                                                    @if ($result['meta_description'])
                                                        <span class="ml-1 normal-case tracking-normal text-paper/25">{{ mb_strlen($result['meta_description']) }} chars</span>
                                                    @endif
                                                </div>
                                                <p class="mt-1 max-w-[70ch] text-[0.82rem] leading-relaxed text-paper/70">{{ $result['meta_description'] ?: '—' }}</p>
                                            </div>
                                        </div>

                                        {{-- Where it goes, and a way to send
                                             it there. A result with no
                                             destination reads as a demo of
                                             the screen rather than a thing
                                             that happened. --}}
                                        <div class="flex flex-wrap items-center gap-3 border-t border-hairline pt-3">
                                            @if (! empty($result['saved']))
                                                <span class="inline-flex items-center gap-2 text-[0.8rem] text-success">
                                                    <x-icon name="circle-check" style="solid" class="text-[0.72rem]" />
                                                    Saved. The tags are on the sound; the description is in
                                                    <a href="{{ route('moderate') }}" wire:navigate class="underline underline-offset-2">In review</a>.
                                                </span>
                                            @else
                                                <span class="text-[0.8rem] text-paper/35">
                                                    This was a test — nothing has been written to
                                                    “{{ $result['sound'] }}”.
                                                </span>

                                                <button type="button" wire:click="keepTest('{{ $sectionKey }}')"
                                                        class="ml-auto inline-flex items-center gap-2 rounded-xl bg-brand px-4 py-2 text-[0.8rem] font-medium text-white transition hover:-translate-y-0.5">
                                                    <x-icon name="floppy-disk" style="solid" class="text-[0.72rem]" />
                                                    Keep it for this sound
                                                </button>
                                            @endif
                                        </div>

                                        {{-- The answer to "fine, but I have a
                                             hundred of these". Testing is one
                                             sound on purpose; the batch is a
                                             different screen because that is
                                             where the results get approved. --}}
                                        @if ($this->pendingCount > 0)
                                            <div class="flex flex-wrap items-center gap-2 rounded-lg bg-rail px-4 py-3 text-[0.82rem]">
                                                <x-icon name="layer-group" style="solid" class="text-[0.75rem] text-info" />
                                                <span class="text-paper/60">
                                                    {{ $this->pendingCount }} more {{ $this->pendingCount === 1 ? 'sound has' : 'sounds have' }}
                                                    never been sent to a model.
                                                </span>

                                                <a href="{{ route('moderate') }}" wire:navigate
                                                   class="ml-auto inline-flex items-center gap-2 rounded-lg bg-raised px-3 py-1.5 text-paper/80 transition hover:bg-rail hover:text-paper">
                                                    Do them all in In review
                                                    <x-icon name="arrow-right" style="solid" class="text-[0.7rem]" />
                                                </a>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach

            <p class="px-1 text-[0.78rem] text-paper/30">
                Every change is written to the activity log. Keys are logged as "replaced" — never with their value.
                Free tiers are free because they are rate limited: a large backfill will be refused partway through,
                and the job simply comes back later rather than failing.
            </p>

            <x-admin.save-bar />
        </form>
    @endif
</div>
