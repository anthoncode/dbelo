<?php

use App\Mail\CampaignMail;
use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\Subscriber;
use App\Services\CampaignSender;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Campaign')] class extends Component {
    public ?int $campaignId = null;

    public string $campaignTitle = '';
    public string $subject = '';
    public string $preheader = '';
    public string $body = '';
    public string $segment = 'all';
    public string $scheduledAt = '';

    public string $tab = 'write';        // write | preview

    /** Typing SEND is the only thing standing between a draft and the list. */
    public bool $confirming = false;
    public string $confirmation = '';

    public bool $tested = false;
    public ?string $savedAt = null;

    public function mount(?Campaign $campaign = null): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        if ($campaign?->exists) {
            abort_if($campaign->isDigest(), 404);

            $this->campaignId = $campaign->id;
            $this->campaignTitle = $campaign->title;
            $this->subject = $campaign->subject;
            $this->preheader = (string) $campaign->preheader;
            $this->body = (string) $campaign->body;
            $this->segment = $campaign->segment;
            $this->scheduledAt = $campaign->scheduled_at?->format('Y-m-d\TH:i') ?? '';
            $this->tested = $campaign->sends()->whereNotNull('sent_at')->exists();
        }
    }

    #[Computed]
    public function campaign(): ?Campaign
    {
        return $this->campaignId ? Campaign::find($this->campaignId) : null;
    }

    #[Computed]
    public function preview(): string
    {
        return \Illuminate\Support\Str::markdown($this->body ?: '', [
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);
    }

    /** How many people this would reach, live as the segment changes. */
    #[Computed]
    public function reach(): int
    {
        $probe = new Campaign(['type' => Campaign::TYPE_PROMO, 'segment' => $this->segment]);

        return app(CampaignSender::class)->count($probe);
    }

    #[Computed]
    public function locked(): bool
    {
        return $this->campaign && ! $this->campaign->isEditable();
    }

    protected function rules(): array
    {
        return [
            'campaignTitle' => ['required', 'string', 'max:160'],
            'subject' => ['required', 'string', 'max:160'],
            'preheader' => ['nullable', 'string', 'max:160'],
            'body' => ['required', 'string', 'min:20'],
            'segment' => ['required', Rule::in(array_keys(Campaign::SEGMENTS))],
        ];
    }

    public function save(bool $redirect = true): ?Campaign
    {
        if ($this->locked) {
            session()->flash('error', 'This campaign has already gone out.');

            return null;
        }

        $this->validate($this->rules(), [
            'body.min' => 'Write something first.',
        ]);

        $campaign = $this->campaignId ? Campaign::findOrFail($this->campaignId) : new Campaign;
        $isNew = ! $campaign->exists;

        $campaign->fill([
            'type' => Campaign::TYPE_PROMO,
            'user_id' => $campaign->user_id ?? auth()->id(),
            'title' => $this->campaignTitle,
            'subject' => $this->subject,
            'preheader' => $this->preheader ?: null,
            'body' => $this->body,
            'segment' => $this->segment,
            'scheduled_at' => $this->scheduledAt ? Carbon::parse($this->scheduledAt) : null,
            'status' => $this->scheduledAt ? Campaign::STATUS_SCHEDULED : Campaign::STATUS_DRAFT,
        ])->save();

        $this->campaignId = $campaign->id;
        $this->savedAt = now()->format('H:i');

        unset($this->campaign);

        if ($isNew && $redirect) {
            $this->redirectRoute('admin.campaigns.edit', $campaign, navigate: true);

            return $campaign;
        }

        session()->flash('ok', 'Saved.');

        return $campaign;
    }

    /**
     * To yourself, always. Nothing else on this screen is reversible, and a
     * typo read on a phone is not the same as a typo read in this editor.
     */
    public function sendTest(): void
    {
        $campaign = $this->save(redirect: false);

        if (! $campaign) {
            return;
        }

        $me = Subscriber::firstWhere('user_id', auth()->id())
            ?? Subscriber::add(auth()->user()->email, [
                'user_id' => auth()->id(),
                'name' => auth()->user()->name,
                'source' => 'admin',
            ]);

        Mail::to($me->email)->send(new CampaignMail($campaign, $me));

        $this->tested = true;

        session()->flash('ok', "Test sent to {$me->email}. Read it on a phone before sending for real.");
    }

    public function startSend(): void
    {
        if (! $this->save(redirect: false)) {
            return;
        }

        $this->confirming = true;
        $this->confirmation = '';
    }

    public function send(): void
    {
        $this->validate(['confirmation' => ['required', 'in:SEND']], [
            'confirmation.in' => 'Type SEND in capitals to confirm.',
            'confirmation.required' => 'Type SEND in capitals to confirm.',
        ]);

        $campaign = Campaign::findOrFail($this->campaignId);

        if (! $campaign->isEditable()) {
            session()->flash('error', 'This campaign has already gone out.');

            return;
        }

        $queued = app(CampaignSender::class)->dispatch($campaign);

        ActivityLog::record('campaign.sent', $campaign,
            "{$campaign->title} queued to {$queued} recipients", ['segment' => $campaign->segment]);

        $this->confirming = false;
        unset($this->campaign);

        session()->flash('ok', $queued
            ? "Queued to {$queued} people. The worker sends them one by one — the list updates as it goes."
            : 'Nobody in that segment wants this kind of email.');
    }
}; ?>

<div x-data="{
        pos: null,
        remember() { const el = this.$refs.editor; if (el) this.pos = [el.selectionStart, el.selectionEnd]; },
        apply(before, after = '', placeholder = 'text') {
            const el = this.$refs.editor;
            if (! el) return;
            el.focus();
            const [s, e] = this.pos ?? [el.selectionStart, el.selectionEnd];
            const chosen = el.value.slice(s, e) || placeholder;
            el.setRangeText(before + chosen + after, s, e, 'end');
            el.dispatchEvent(new Event('input'));
            this.remember();
        },
     }">

    @if (session('ok'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-success/30 bg-success/10 px-4 py-3">
            <x-icon name="circle-check" style="solid" class="text-success" />
            <span class="text-[0.88rem]">{{ session('ok') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-danger/30 bg-danger/10 px-4 py-3">
            <x-icon name="triangle-exclamation" style="solid" class="text-danger" />
            <span class="text-[0.88rem]">{{ session('error') }}</span>
        </div>
    @endif

    <a href="{{ route('admin.campaigns') }}" wire:navigate
       class="mb-4 inline-flex items-center gap-2 text-[0.8rem] text-paper/35 transition hover:text-paper">
        <x-icon name="arrow-left" style="solid" class="text-[11px]" />
        All campaigns
    </a>

    @if ($this->locked)
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-info/30 bg-info/10 px-4 py-3">
            <x-icon name="lock" style="solid" class="text-info" />
            <span class="text-[0.88rem]">
                Sent {{ $this->campaign->sent_at?->format('M j, Y · H:i') }} to
                {{ number_format($this->campaign->sent_count) }} people. It cannot be edited — it is already in inboxes.
            </span>
        </div>
    @endif

    <div class="grid gap-5 lg:grid-cols-[1fr_320px] xl:grid-cols-[1fr_360px]">

        {{-- ══════ COLUMN 1 ══════ --}}
        <div class="min-w-0 space-y-5">
            <div class="rounded-2xl border border-hairline bg-panel">

                <div class="space-y-4 border-b border-hairline p-5">
                    <input type="text" wire:model="campaignTitle" @disabled($this->locked)
                           placeholder="Internal name — nobody sees this"
                           class="w-full border-0 bg-transparent p-0 text-[1.4rem] font-semibold tracking-[-0.02em] text-paper placeholder:text-paper/20 focus:outline-none focus:ring-0 disabled:opacity-60" />
                    @error('campaignTitle') <p class="text-[0.8rem] text-danger">{{ $message }}</p> @enderror

                    <div>
                        <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Subject</label>
                        <input type="text" wire:model.live.debounce.400ms="subject" @disabled($this->locked)
                               class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.9rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:opacity-60" />
                        @error('subject') <p class="mt-1.5 text-[0.8rem] text-danger">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Preheader</label>
                        <input type="text" wire:model.live.debounce.400ms="preheader" @disabled($this->locked)
                               placeholder="The grey line next to the subject in the inbox"
                               class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.88rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:opacity-60" />
                    </div>

                    {{-- What the inbox will actually show. Subject lines get
                         written blind and then cut off at 45 characters. --}}
                    <div class="rounded-xl bg-raised p-4">
                        <div class="mb-2 text-[0.7rem] uppercase tracking-[0.14em] text-paper/30">In the inbox</div>
                        <div class="flex items-baseline gap-2">
                            <span class="text-[0.88rem] font-medium">dbelo</span>
                            <span class="text-[0.7rem] text-paper/25">now</span>
                        </div>
                        <div class="mt-1 truncate text-[0.88rem]">{{ $subject ?: 'No subject yet' }}</div>
                        <div class="truncate text-[0.8rem] text-paper/35">{{ $preheader ?: Str::limit(strip_tags($this->preview), 70) }}</div>
                    </div>
                </div>

                {{-- Write / Preview --}}
                <div class="flex items-center gap-1.5 border-b border-hairline px-3 py-2">
                    @foreach (['write' => ['Write', 'pen'], 'preview' => ['Preview', 'eye']] as $key => [$label, $icon])
                        <button wire:click="$set('tab', '{{ $key }}')"
                                @class([
                                    'flex items-center gap-2 rounded-lg px-3.5 py-2 text-[0.82rem] transition duration-200 ease-dbelo',
                                    'bg-raised text-paper' => $tab === $key,
                                    'text-paper/45 hover:text-paper' => $tab !== $key,
                                ])>
                            <x-icon :name="$icon" style="solid" class="text-[11px]" />
                            {{ $label }}
                        </button>
                    @endforeach

                    @if ($savedAt)
                        <span class="ml-auto flex items-center gap-1.5 pr-2 text-[0.72rem] text-paper/25">
                            <x-icon name="cloud-check" style="solid" class="text-[10px] text-success/70" />
                            {{ $savedAt }}
                        </span>
                    @endif
                </div>

                @if ($tab === 'write')
                    <div class="flex flex-wrap items-center gap-1 border-b border-hairline px-3 py-2">
                        @foreach ([
                            ['bold', 'Bold', '**', '**', 'bold text'],
                            ['italic', 'Italic', '*', '*', 'italic text'],
                            ['heading', 'Heading', '## ', '', 'Heading'],
                            ['link', 'Link', '[', '](https://)', 'link text'],
                            ['list-ul', 'Bullet list', '- ', '', 'item'],
                            ['quote-left', 'Quote', '> ', '', 'quote'],
                            ['minus', 'Divider', "\n---\n", '', ''],
                        ] as [$icon, $label, $before, $after, $placeholder])
                            <span class="group/tip relative">
                                <button type="button" @click="apply(@js($before), @js($after), @js($placeholder))"
                                        @disabled($this->locked)
                                        class="grid size-8 place-items-center rounded-full text-paper/45 transition duration-200 ease-dbelo hover:bg-paper/[0.10] hover:text-paper disabled:opacity-30">
                                    <x-icon :name="$icon" style="solid" class="text-[11px]" />
                                </button>
                                <span class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 -translate-x-1/2 whitespace-nowrap rounded-lg bg-paper px-2.5 py-1 text-[0.7rem] font-medium text-ink opacity-0 shadow-xl transition group-hover/tip:opacity-100">{{ $label }}</span>
                            </span>
                        @endforeach
                    </div>

                    <div class="p-5">
                        <textarea x-ref="editor" wire:model.live.debounce.900ms="body" @disabled($this->locked)
                                  @keyup="remember()" @mouseup="remember()" @blur="remember()"
                                  rows="18"
                                  placeholder="Keep it short. One idea, one link, one reason to click."
                                  class="w-full resize-y border-0 bg-transparent p-0 font-mono text-[0.88rem] leading-[1.75] text-paper placeholder:text-paper/20 focus:outline-none focus:ring-0 disabled:opacity-60"></textarea>
                        @error('body') <p class="mt-2 text-[0.8rem] text-danger">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div class="bg-canvas/40 p-8">
                        <x-prose class="!text-paper/75 [&_h2]:!text-paper [&_h3]:!text-paper [&_strong]:!text-paper [&_hr]:!border-paper/10">
                            {!! $this->preview !!}
                        </x-prose>
                    </div>
                @endif
            </div>
        </div>

        {{-- ══════ COLUMN 2 ══════ --}}
        <div class="h-fit space-y-5 lg:sticky lg:top-6">

            {{-- Actions --}}
            <div class="rounded-2xl border border-hairline bg-panel p-5">
                @unless ($this->locked)
                    <button wire:click="save"
                            class="flex w-full items-center justify-center gap-2 rounded-lg bg-raised px-4 py-2.5 text-[0.85rem] text-paper/70 transition hover:bg-paper/[0.10] hover:text-paper">
                        <x-icon name="floppy-disk" style="solid" class="text-[11px]" />
                        Save draft
                    </button>

                    <button wire:click="sendTest"
                            class="mt-2 flex w-full items-center justify-center gap-2 rounded-lg bg-info/15 px-4 py-2.5 text-[0.85rem] text-info transition hover:bg-info/25">
                        <x-icon name="paper-plane" style="solid" class="text-[11px]" />
                        Send a test to me
                    </button>

                    {{-- Locked until a test has gone out. The one mistake this
                         screen can make is unrecoverable, so the cheap check
                         is mandatory rather than suggested. --}}
                    <button wire:click="startSend" @disabled(! $tested)
                            @class([
                                'mt-2 flex w-full items-center justify-center gap-2 rounded-lg px-4 py-3 text-[0.88rem] font-medium transition duration-200 ease-dbelo',
                                'bg-action text-white hover:brightness-110' => $tested,
                                'bg-raised text-paper/25' => ! $tested,
                            ])>
                        <x-icon name="rocket" style="solid" class="text-[12px]" />
                        Send to {{ number_format($this->reach) }} people
                    </button>

                    @unless ($tested)
                        <p class="mt-2 text-[0.73rem] leading-relaxed text-paper/30">
                            Send yourself a test first. This is the only button here that cannot be undone.
                        </p>
                    @endunless
                @else
                    <dl class="divide-y divide-hairline text-[0.83rem]">
                        @foreach ([
                            'Recipients' => number_format($this->campaign->recipients_count),
                            'Delivered' => number_format($this->campaign->sent_count),
                            'Failed' => number_format($this->campaign->failed_count),
                        ] as $label => $value)
                            <div class="flex items-center justify-between py-2.5">
                                <dt class="text-paper/35">{{ $label }}</dt>
                                <dd class="tabular-nums">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endunless
            </div>

            {{-- Who gets it --}}
            <div class="rounded-2xl border border-hairline bg-panel">
                <div class="flex items-center gap-3 border-b border-hairline px-5 py-4">
                    <x-admin.icon-chip icon="users" tone="brand" />
                    <h2 class="text-[0.95rem] font-medium">Who gets it</h2>
                </div>

                <div class="p-5">
                    <select wire:model.live="segment" @disabled($this->locked)
                            class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.85rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:opacity-60">
                        @foreach (\App\Models\Campaign::SEGMENTS as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>

                    <div class="mt-3 flex items-baseline gap-2">
                        <span class="text-[1.5rem] font-semibold leading-none tracking-[-0.03em]">{{ number_format($this->reach) }}</span>
                        <span class="text-[0.78rem] text-paper/35">will receive it</span>
                    </div>

                    @if ($segment === 'paying')
                        <p class="mt-3 rounded-lg bg-warning/10 px-3.5 py-2.5 text-[0.75rem] leading-relaxed text-paper/60">
                            These people already pay. Never send them “upgrade to Pro” — showing a subscriber you do
                            not know they are one is the fastest way to lose them.
                        </p>
                    @endif

                    <p class="mt-3 text-[0.73rem] leading-relaxed text-paper/30">
                        Anyone who turned off offers is excluded automatically, whatever the segment.
                    </p>
                </div>
            </div>

            {{-- Schedule --}}
            @unless ($this->locked)
                <div class="rounded-2xl border border-hairline bg-panel">
                    <div class="flex items-center gap-3 border-b border-hairline px-5 py-4">
                        <x-admin.icon-chip icon="clock" tone="muted" />
                        <h2 class="text-[0.95rem] font-medium">Schedule</h2>
                    </div>

                    <div class="p-5">
                        <input type="datetime-local" wire:model="scheduledAt"
                               class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.85rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40" />
                        <p class="mt-2 text-[0.73rem] leading-relaxed text-paper/30">
                            Leave empty to send by hand. A date here queues it for later — and it still needs a test first.
                        </p>
                    </div>
                </div>
            @endunless
        </div>
    </div>

    {{-- ══════ CONFIRM ══════ --}}
    @if ($confirming)
        <div class="fixed inset-0 z-[100] flex items-center justify-center bg-rail/80 p-6 backdrop-blur-sm"
             wire:click.self="$set('confirming', false)">

            <div class="w-full max-w-lg rounded-2xl border border-hairline bg-panel p-7 shadow-float">
                <div class="flex items-start gap-4">
                    <span class="mt-0.5 grid size-10 shrink-0 place-items-center rounded-full bg-action/15 text-action">
                        <x-icon name="rocket" style="solid" class="text-[14px]" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <h2 class="text-[1.05rem] font-medium">Send to {{ number_format($this->reach) }} people?</h2>

                        <p class="mt-2 text-[0.83rem] leading-relaxed text-paper/45">
                            Segment: <span class="text-paper/70">{{ Campaign::SEGMENTS[$segment] }}</span>.
                            Subject: <span class="text-paper/70">{{ $subject }}</span>.
                        </p>

                        <p class="mt-2 text-[0.83rem] leading-relaxed text-paper/45">
                            Email cannot be recalled. Nothing about this is undoable once the worker starts.
                        </p>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <input type="text" wire:model="confirmation" autofocus wire:keydown.enter="send"
                                   placeholder="Type SEND to confirm"
                                   class="min-w-0 flex-1 rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.88rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-action/40" />
                            <button wire:click="send"
                                    class="rounded-lg bg-action px-5 py-2.5 text-[0.85rem] font-medium text-white transition hover:brightness-110">
                                Send now
                            </button>
                            <button wire:click="$set('confirming', false)"
                                    class="rounded-lg bg-raised px-4 py-2.5 text-[0.85rem] text-paper/55 transition hover:text-paper">
                                Cancel
                            </button>
                        </div>

                        @error('confirmation') <p class="mt-2 text-[0.8rem] text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
