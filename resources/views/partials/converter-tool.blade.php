{{--
    The converter itself — the drop zone, the queue and the action bar.

    ONE DEFINITION, TWO SURFACES. /converter and every /convert/{pair} page
    render this same partial. Copying the markup into the pair template
    would mean twenty pages quietly running an older version of the tool the
    first time either file was edited, and nothing anywhere would say so.

    Expects an Alpine `converter(...)` scope on an ancestor, and $cfg.
    $pair is optional: when present the target format is fixed and the
    format controls disappear, because the visitor already told us both
    halves by the page they opened.
--}}
{{-- ══════════════════════════════════════════════════════════════
     DROP ZONE — collapses once there are files
     ══════════════════════════════════════════════════════════════ --}}
<div class="mt-10 rounded-card bg-surface p-6 shadow-soft-md sm:p-8 dark:bg-surface-dark">

    <div x-data="{ over: false }"
         x-on:dragover.prevent="over = true"
         x-on:dragleave.prevent="over = false"
         x-on:drop.prevent="over = false; add($event.dataTransfer.files)"
         @click="$refs.picker.click()"
         role="button" tabindex="0"
         x-on:keydown.enter="$refs.picker.click()"
         class="cursor-pointer rounded-2xl border-2 border-dashed text-center transition duration-300 ease-dbelo"
         :class="[
             over ? 'border-action bg-action/[0.04]' : 'border-ink/15 hover:border-brand/50 dark:border-paper/15',
             files.length ? 'px-6 py-6' : 'px-6 py-12',
         ]">

        <input x-ref="picker" type="file" multiple class="hidden"
               accept="{{ implode(',', $cfg['accepts']) }}"
               x-on:change="add($event.target.files); $event.target.value = ''" />

        <span class="mx-auto grid place-items-center rounded-full bg-brand/10 text-brand transition-all"
              :class="files.length ? 'size-10' : 'size-14'">
            <x-icon name="waveform-lines" style="solid" class="text-[1.05rem]" />
        </span>

        <p class="mt-3 font-medium" :class="files.length ? 'text-[0.9rem]' : 'text-[1rem]'"
           x-text="files.length ? 'Add more files' : 'Drop your audio here, or click to choose'"></p>

        <p class="mt-1 text-[0.82rem] text-ink/45 dark:text-paper/45">
            Audio only · up to {{ \App\Support\Converter::MAX_FILES }} files ·
            {{ (int) (\App\Support\Converter::MAX_INPUT_BYTES / 1024 / 1024) }} MB each
            <span class="text-ink/30 dark:text-paper/30">
                — those numbers protect <em>your</em> memory, not our server
            </span>
        </p>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════
     THE QUEUE — one row, one destination, one set of settings
     ══════════════════════════════════════════════════════════════ --}}
<div x-show="files.length" x-cloak class="mt-5 rounded-card bg-surface shadow-soft-md dark:bg-surface-dark">

    <div class="divide-y divide-ink/[0.07] dark:divide-paper/[0.09]">
        <template x-for="f in files" :key="f.id">
            <div :class="flashed(f) ? 'dbelo-flash' : ''">
                {{-- ── The row ── --}}
                <div class="flex flex-wrap items-center gap-3 px-5 py-3.5 sm:flex-nowrap">

                    {{-- A file, not a musical note. The note said
                         "music"; this says "an audio file", which is
                         what the row actually holds. The shadow lifts
                         it half a millimetre off the surface so the
                         list reads as a stack of things rather than a
                         column of icons. --}}
                    {{-- Duotone: the sheet in the lighter tone, the note in
                         the solid one, which is the shape of the reference.

                         The two colours are set through Font Awesome's own
                         custom properties and pointed at the design tokens,
                         so the icon can never drift from the palette — and
                         because they are inline they survive a stale build,
                         where a never-compiled utility class would leave the
                         icon a flat single colour with nothing to say why.

                         THE RESTING COLOUR IS INFO, NOT BRAND. Brand is
                         identity — the logo, the playhead, PRO — and a file
                         waiting in a queue is none of those. Info is the
                         neutral fact, which is exactly what this row is
                         until something happens to it, and it is the same
                         meaning the colour carries in the admin. --}}
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl shadow-soft-sm transition"
                          :class="{
                            'bg-success/10': f.status === 'done',
                            'bg-danger/[0.08]': f.status === 'error' || f.status === 'rejected',
                            'bg-warning/[0.08]': f.status === 'blocked',
                            'bg-info/[0.08]': f.status === 'queued' || f.status === 'working',
                          }">
                        <i class="fa-duotone fa-light text-[1.05rem]" aria-hidden="true"
                           :class="f.status === 'rejected' ? 'fa-file-circle-xmark' : 'fa-file-audio'"
                           :style="`
                             --fa-primary-color: ${
                                f.status === 'done' ? 'var(--color-success)'
                              : f.status === 'error' || f.status === 'rejected' ? 'var(--color-danger)'
                              : f.status === 'blocked' ? 'var(--color-warning)'
                              : 'var(--color-info)'};
                             --fa-secondary-color: ${
                                f.status === 'done' ? 'var(--color-success)'
                              : f.status === 'error' || f.status === 'rejected' ? 'var(--color-danger)'
                              : f.status === 'blocked' ? 'var(--color-warning)'
                              : 'var(--color-info)'};
                             --fa-primary-opacity: 1;
                             --fa-secondary-opacity: 0.32;
                           `"></i>
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="truncate text-[0.88rem]" x-text="f.name" :title="f.name"></div>
                        <div class="mt-0.5 text-[0.75rem] text-ink/40 dark:text-paper/40">
                            <span x-text="bytes(f.size)"></span>
                            <template x-if="f.duration">
                                <span> · <span x-text="time(f.duration)"></span></span>
                            </template>
                            <template x-if="trimmed(f)">
                                <span class="text-brand"> · trimmed</span>
                            </template>
                        </div>
                    </div>

                    {{-- to [FORMAT] — the control the reference gets
                         right and a global selector gets wrong. --}}
                    <div class="flex shrink-0 items-center gap-2">
                        {{-- On a pair page the destination IS the page. A
                             dropdown holding one right answer is furniture,
                             not a control, so it becomes a label. --}}
                        <span class="text-[0.78rem] text-ink/35 dark:text-paper/35">to</span>

                        <template x-if="locked">
                            <span class="rounded-lg bg-brand/10 px-3 py-1.5 text-[0.82rem] font-medium text-brand"
                                  x-text="fmt(f).label"></span>
                        </template>

                        <template x-if="!locked">

                        {{-- Enabled again once the row is done: converting
                             the same file to a second format is a normal
                             thing to want, and deleting and re-dropping it
                             is not an answer. --}}
                        <select x-model="f.target" @change="setTarget(f, f.target)"
                                :disabled="f.status === 'working'"
                                class="rounded-lg border border-ink/10 bg-transparent py-1.5 pl-2.5 pr-7 text-[0.82rem] font-medium focus:outline-none focus:ring-1 focus:ring-brand disabled:opacity-40 dark:border-paper/15">
                            <template x-for="fmt in formatList" :key="fmt.key">
                                <option :value="fmt.key" x-text="fmt.label"></option>
                            </template>
                        </select>
                        </template>

                        <button type="button"
                                @click="reopen(f); editing = (editing === f.id ? null : f.id)"
                                :disabled="f.status === 'working'"
                                aria-label="Settings"
                                class="grid size-8 place-items-center rounded-lg border border-ink/10 text-ink/40 transition hover:border-brand hover:text-brand disabled:opacity-40 dark:border-paper/15 dark:text-paper/40"
                                :class="editing === f.id ? 'border-brand text-brand' : ''">
                            <i class="fa-solid fa-gear text-[0.78rem]" aria-hidden="true"></i>
                        </button>
                    </div>

                    {{-- Status badge --}}
                    <span class="shrink-0 rounded px-2 py-1 text-[0.64rem] font-bold tracking-[0.06em]"
                          :class="{
                            'bg-success/15 text-success': badge(f).tone === 'ready' || badge(f).tone === 'done',
                            'bg-brand/15 text-brand': badge(f).tone === 'working',
                            'bg-danger/15 text-danger': badge(f).tone === 'error',
                            'bg-warning/15 text-warning': badge(f).tone === 'blocked',
                          }"
                          x-text="badge(f).text"></span>

                    {{-- Three states, because there are three.

                         A dash while the metadata is still loading reads as
                         "we cannot know", when the answer is arriving in two
                         hundred milliseconds. The ellipsis says measuring;
                         the dash is kept only for a file whose length really
                         cannot be read, and it carries the explanation on
                         hover instead of standing there mute. --}}
                    <span class="w-20 shrink-0 text-right text-[0.78rem] tabular-nums text-ink/45 dark:text-paper/45">
                        <template x-if="f.status === 'done'">
                            <span class="text-success" x-text="bytes(f.outSize)"></span>
                        </template>

                        <template x-if="f.status !== 'done' && f.probing">
                            <span class="text-ink/25 dark:text-paper/25">…</span>
                        </template>

                        <template x-if="f.status !== 'done' && !f.probing">
                            <span :title="estimateNote(f)"
                                  :class="estimateFor(f) ? '' : 'cursor-help text-ink/25 dark:text-paper/25'"
                                  x-text="estimateFor(f) ?? '—'"></span>
                        </template>
                    </span>

                    <template x-if="f.status === 'done'">
                        <a :href="f.url" :download="f.outName"
                           class="grid size-9 shrink-0 place-items-center rounded-full bg-action text-white transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:brightness-110"
                           aria-label="Download">
                            <i class="fa-solid fa-arrow-down-to-line text-[0.78rem]" aria-hidden="true"></i>
                        </a>
                    </template>

                    <button type="button" @click="remove(f.id)"
                            :disabled="f.status === 'working'"
                            aria-label="Remove"
                            class="grid size-8 shrink-0 place-items-center rounded-full text-ink/25 transition hover:bg-ink/[0.06] hover:text-danger disabled:opacity-30 dark:text-paper/25 dark:hover:bg-paper/[0.08]">
                        <i class="fa-regular fa-xmark text-[0.85rem]" aria-hidden="true"></i>
                    </button>
                </div>

                {{-- ── Per-file progress ── --}}
                <template x-if="f.status === 'working'">
                    <div class="px-5 pb-3.5">
                        <div class="h-1.5 overflow-hidden rounded-full bg-ink/[0.07] dark:bg-paper/[0.09]">
                            <div class="h-full rounded-full bg-gradient-to-r from-brand to-brand/50 transition-[width] duration-300"
                                 :style="`width: ${Math.max(3, f.progress)}%`"></div>
                        </div>
                    </div>
                </template>

                {{-- ── Why it will not run. Said before any waiting. ── --}}
                {{-- The cause, contained.

                     min-w-0 and break-words are what stop a long
                     ffmpeg error — which arrives as one unbroken
                     string — from widening the row and pushing the
                     buttons off the right edge. The sentence a
                     person can act on comes first; the engine's own
                     words sit under it, quieter, for when they are
                     the thing that helps. --}}
                <template x-if="f.message">
                    <div class="px-5 pb-3.5">
                        <div class="min-w-0 rounded-xl px-3.5 py-2.5"
                             :class="f.status === 'error' || f.status === 'rejected'
                                ? 'bg-danger/[0.07]' : 'bg-warning/[0.07]'">
                            <p class="break-words text-[0.8rem] leading-relaxed"
                               :class="f.status === 'error' || f.status === 'rejected' ? 'text-danger' : 'text-warning'"
                               x-text="f.message"></p>

                            <template x-if="f.detail">
                                <p class="mt-1.5 max-h-16 overflow-y-auto break-all font-mono text-[0.72rem] leading-relaxed text-ink/40 dark:text-paper/40"
                                   x-text="f.detail"></p>
                            </template>
                        </div>
                    </div>
                </template>

                <template x-if="f.status === 'queued' && warnMobile(f)">
                    <p class="px-5 pb-3.5 text-[0.8rem] leading-relaxed text-warning">
                        Large for a phone. It may work — but if the tab closes itself, that is why,
                        and a computer will handle it.
                    </p>
                </template>

                {{-- ══════════════════════════════════════════════
                     SETTINGS — inline, not a modal
                     ══════════════════════════════════════════════
                     A dialog would cover the row it belongs to and
                     the estimate that changes as you edit it. Here
                     the size on the right updates while you type a
                     trim, which is the whole feedback loop. --}}
                <template x-if="editing === f.id">
                    <div class="border-t border-ink/[0.07] bg-ink/[0.02] px-5 py-5 dark:border-paper/[0.09] dark:bg-paper/[0.02]">

                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">

                            {{-- Cut --}}
                            <div class="sm:col-span-2 lg:col-span-1">
                                <label class="block text-[0.76rem] text-ink/45 dark:text-paper/45">Cut</label>
                                <div class="mt-1.5 flex items-center gap-2">
                                    <input type="text" x-model="f.cutFrom" @input="check(f)"
                                           placeholder="0:00"
                                           class="w-full rounded-lg border border-ink/10 bg-transparent px-2.5 py-2 text-[0.84rem] tabular-nums focus:outline-none focus:ring-1 focus:ring-brand dark:border-paper/15" />
                                    <span class="text-ink/30 dark:text-paper/30">–</span>
                                    <input type="text" x-model="f.cutTo" @input="check(f)"
                                           :placeholder="f.duration ? time(f.duration) : 'end'"
                                           class="w-full rounded-lg border border-ink/10 bg-transparent px-2.5 py-2 text-[0.84rem] tabular-nums focus:outline-none focus:ring-1 focus:ring-brand dark:border-paper/15" />
                                </div>
                                <p class="mt-1 text-[0.72rem] text-ink/30 dark:text-paper/30">
                                    Minutes:seconds. Leave empty for the whole file.
                                </p>
                            </div>

                            {{-- Codec — bit depth for WAV, bitrate for the rest.
                                 One control that changes shape by format, rather
                                 than two that are each empty half the time. --}}
                            <div>
                                <label class="block text-[0.76rem] text-ink/45 dark:text-paper/45">Codec</label>

                                <template x-if="Object.keys(fmt(f).codecs).length">
                                    <select x-model="f.codec" @change="check(f)"
                                            class="mt-1.5 w-full rounded-lg border border-ink/10 bg-transparent px-2.5 py-2 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand dark:border-paper/15">
                                        <template x-for="(c, key) in fmt(f).codecs" :key="key">
                                            <option :value="key" x-text="c.label"></option>
                                        </template>
                                    </select>
                                </template>

                                <template x-if="fmt(f).rates.length">
                                    <select x-model.number="f.rate" @change="check(f)"
                                            class="mt-1.5 w-full rounded-lg border border-ink/10 bg-transparent px-2.5 py-2 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand dark:border-paper/15">
                                        <template x-for="r in fmt(f).rates" :key="r">
                                            <option :value="r" x-text="r + ' kbps'"></option>
                                        </template>
                                    </select>
                                </template>

                                <template x-if="!fmt(f).rates.length && !Object.keys(fmt(f).codecs).length">
                                    <p class="mt-2.5 text-[0.8rem] text-ink/35 dark:text-paper/35">
                                        Lossless — nothing to choose.
                                    </p>
                                </template>
                            </div>

                            {{-- Channels --}}
                            <div>
                                <label class="block text-[0.76rem] text-ink/45 dark:text-paper/45">Audio channels</label>
                                <select x-model="f.channels" @change="check(f)"
                                        class="mt-1.5 w-full rounded-lg border border-ink/10 bg-transparent px-2.5 py-2 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand dark:border-paper/15">
                                    @foreach ($cfg['channels'] as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Frequency --}}
                            <div>
                                <label class="block text-[0.76rem] text-ink/45 dark:text-paper/45">Frequency</label>
                                <select x-model="f.frequency" @change="check(f)"
                                        class="mt-1.5 w-full rounded-lg border border-ink/10 bg-transparent px-2.5 py-2 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand dark:border-paper/15">
                                    @foreach ($cfg['frequencies'] as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Volume --}}
                            <div>
                                <label class="block text-[0.76rem] text-ink/45 dark:text-paper/45">Volume</label>
                                <select x-model="f.volume"
                                        class="mt-1.5 w-full rounded-lg border border-ink/10 bg-transparent px-2.5 py-2 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand dark:border-paper/15">
                                    @foreach ($cfg['volumes'] as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="mt-5 flex flex-wrap items-center gap-3 border-t border-ink/[0.07] pt-4 dark:border-paper/[0.09]">
                            <button type="button" @click="applyToAll(f); editing = null"
                                    x-show="files.length > 1"
                                    class="rounded-full bg-ink/[0.05] px-4 py-2 text-[0.8rem] text-ink/70 transition hover:bg-ink/[0.09] dark:bg-paper/[0.08] dark:text-paper/70 dark:hover:bg-paper/[0.14]">
                                Apply to all files
                            </button>

                            <span class="text-[0.78rem] text-ink/35 dark:text-paper/35">
                                Result: <span class="tabular-nums"
                                    x-text="f.probing ? 'measuring…' : (estimateFor(f) ?? 'unknown until it runs')"></span>
                            </span>

                            <button type="button" @click="editing = null"
                                    class="ml-auto rounded-full bg-brand px-6 py-2 text-[0.82rem] font-medium text-white transition hover:brightness-110">
                                Ok
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </template>
    </div>

    {{-- ══════════════════════════════════════════════════════════
         CONVERT ALL TO  +  THE BUTTON  +  OVERALL PROGRESS
         ══════════════════════════════════════════════════════════ --}}
    <div class="border-t border-ink/[0.07] px-5 py-5 dark:border-paper/[0.09]">

        <template x-if="engine === 'loading'">
            <p class="mb-4 flex items-center gap-2.5 text-[0.86rem] text-ink/50 dark:text-paper/50">
                <i class="fa-solid fa-spinner fa-spin text-[0.8rem] text-brand" aria-hidden="true"></i>
                Getting the converter ready — this happens once, then your browser keeps it.
            </p>
        </template>

        {{-- The failure says WHAT HAPPENED, not what we guessed.

             This used to read "your browser does not support
             WebAssembly" — a cause nothing had checked, about a
             feature every browser has had for eight years. It was
             wrong nearly every time it showed, and it pointed
             whoever read it at the one place the problem was not. --}}
        <template x-if="engine === 'failed'">
            <div class="mb-4 rounded-xl border border-danger/25 bg-danger/[0.06] px-4 py-3.5">
                <p class="text-[0.88rem] text-danger">The converter could not start.</p>
                <p class="mt-1.5 break-words font-mono text-[0.78rem] leading-relaxed text-ink/55 dark:text-paper/55"
                   x-text="engineError || 'No error message was reported — check the browser console.'"></p>
                {{-- The hint only appears when it applies. Printing
                     "if that mentions a 404" under an error that says
                     nothing about a 404 is the same lie in a smaller
                     font: it invites somebody to go and check the
                     thing that is already fine. --}}
                <template x-if="engineError.includes('404') || engineError.includes('not installed')">
                    <p class="mt-2 text-[0.78rem] leading-relaxed text-ink/40 dark:text-paper/40">
                        The engine files are missing from
                        <span class="font-mono" x-text="cfg.core"></span> —
                        copy <span class="font-mono">ffmpeg-core.js</span> and
                        <span class="font-mono">ffmpeg-core.wasm</span> there.
                    </p>
                </template>

                {{-- "failed to import" has one overwhelmingly likely
                     cause and it is not obvious from the words.

                     The self-hosted worker is an ES module, and a
                     module worker cannot importScripts() — it calls
                     import(). Hand it the UMD build of the core and
                     the import fails with exactly this text. The two
                     halves have to come from the same family. --}}
                <template x-if="engineError.includes('failed to import')">
                    <p class="mt-2 text-[0.78rem] leading-relaxed text-ink/40 dark:text-paper/40">
                        Almost always a mismatched build: the worker is an ES module, so the core has to be
                        the <span class="font-mono">esm</span> one too. Copy
                        <span class="font-mono">ffmpeg-core.js</span> and
                        <span class="font-mono">.wasm</span> from
                        <span class="font-mono">@ffmpeg/core/dist/esm/</span>, not
                        <span class="font-mono">dist/umd/</span>.
                    </p>
                </template>
            </div>
        </template>

        <template x-if="hidden.length">
            <p class="mb-4 text-[0.82rem] leading-relaxed text-warning">
                <span x-text="hiddenLabels"></span>
                <span x-text="hidden.length === 1 ? ' is' : ' are'"></span>
                not in this build of the converter, so it is not offered.
            </p>
        </template>

        {{-- The batch bar. Separate from the per-file one because
             they answer different questions: this one is "how much
             longer until I can leave". --}}
        <template x-if="running">
            <div class="mb-4">
                <div class="mb-1.5 flex items-baseline justify-between text-[0.78rem] text-ink/45 dark:text-paper/45">
                    <span><span x-text="done.length"></span> of <span x-text="eligible.length"></span> done</span>
                    <span class="tabular-nums" x-text="overall + '%'"></span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-ink/[0.07] dark:bg-paper/[0.09]">
                    <div class="h-full rounded-full bg-gradient-to-r from-brand to-action transition-[width] duration-300"
                         :style="`width: ${Math.max(2, overall)}%`"></div>
                </div>
            </div>
        </template>

        <div class="flex flex-wrap items-center gap-3">

            <div class="flex items-center gap-2" x-show="!locked" x-cloak>
                <span class="text-[0.82rem] text-ink/45 dark:text-paper/45">Convert all to</span>
                <select x-model="bulk" @change="setAllTargets(bulk)"
                        :disabled="running"
                        class="rounded-lg border border-ink/10 bg-transparent py-1.5 pl-2.5 pr-7 text-[0.82rem] focus:outline-none focus:ring-1 focus:ring-brand disabled:opacity-40 dark:border-paper/15">
                    <option value="">—</option>
                    <template x-for="fmt in formatList" :key="fmt.key">
                        <option :value="fmt.key" x-text="fmt.label"></option>
                    </template>
                </select>
            </div>

            <button type="button" @click="clear()" x-show="!running"
                    class="text-[0.8rem] text-ink/40 underline underline-offset-2 transition hover:text-ink/70 dark:text-paper/40 dark:hover:text-paper/70">
                Clear all
            </button>

            {{-- ONCE THE BATCH IS DONE, THE BUTTON CHANGES JOB.

                 Leaving "Convert 4 files" sitting there greyed out
                 after it has converted four files is a dead end: the
                 next thing anybody wants is the files, and the thing
                 after that is to do it again. So the primary action
                 becomes Download all, and Convert more files empties
                 the queue and returns to the drop zone. --}}
            <div class="ml-auto flex flex-wrap items-center gap-3">

                <template x-if="finished">
                    <button type="button" @click="reset()"
                            class="rounded-full bg-ink/[0.05] px-6 py-3.5 text-[0.85rem] text-ink/70 transition duration-300 ease-dbelo hover:bg-ink/[0.09] dark:bg-paper/[0.08] dark:text-paper/70 dark:hover:bg-paper/[0.14]">
                        Convert more files
                    </button>
                </template>

                <template x-if="finished">
                    <button type="button" @click="downloadAll()"
                            class="flex items-center gap-2.5 rounded-full bg-success px-8 py-3.5 font-medium text-ink transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:brightness-110">
                        <i class="fa-solid fa-arrow-down-to-line text-[0.8rem]" aria-hidden="true"></i>
                        <span x-text="done.length > 1 ? `Download all ${done.length}` : 'Download'"></span>
                    </button>
                </template>

                <template x-if="!finished">
                    <button type="button" @click="run()"
                            :disabled="running || !pending.length || engine === 'failed'"
                            class="flex items-center gap-2.5 rounded-full bg-action px-8 py-3.5 font-medium text-white transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-40">
                        <template x-if="!running">
                            <span class="flex items-center gap-2.5">
                                <span x-text="pending.length > 1 ? `Convert ${pending.length} files` : 'Convert'"></span>
                                <i class="fa-solid fa-arrow-right text-[0.8rem]" aria-hidden="true"></i>
                            </span>
                        </template>
                        <template x-if="running">
                            <span class="flex items-center gap-2.5">
                                <i class="fa-solid fa-spinner fa-spin text-[0.85rem]" aria-hidden="true"></i>
                                Converting…
                            </span>
                        </template>
                    </button>
                </template>
            </div>
        </div>
    </div>
</div>

{{-- No JavaScript: a sentence beats a dead drop zone. --}}
<noscript>
    <div class="mt-5 rounded-card bg-surface p-6 text-[0.88rem] leading-relaxed text-ink/60 shadow-soft-md dark:bg-surface-dark dark:text-paper/60">
        This converter runs entirely in your browser, so it needs JavaScript switched on. That is also
        the reason your file never gets uploaded anywhere.
    </div>
</noscript>
