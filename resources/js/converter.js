/*
 * The free audio converter.
 *
 * A SEPARATE VITE ENTRY, not part of app.js, and that is load-bearing:
 * app.js ships on every page of the public site, and nothing else needs
 * this. Only /converter loads it.
 *
 * WHAT IS IMPORTED HERE IS TINY. @ffmpeg/ffmpeg and @ffmpeg/util are a few
 * kilobytes of wrapper. The actual ffmpeg — around 30 MB of WebAssembly —
 * is NOT bundled: it is fetched at runtime from /vendor/ffmpeg, and only
 * once the visitor has chosen a file.
 *
 * That lazy load is not an optimisation, it is the whole SEO case for the
 * module. A page that downloads 30 MB before it can be read has Core Web
 * Vitals that guarantee it ranks for nothing.
 *
 * SELF-HOSTED, NEVER A CDN. Pulling the core from unpkg would mean a third
 * party sees every visitor to a page whose entire promise is that the file
 * goes nowhere.
 *
 * ─────────────────────────────────────────────────────────────────────
 * EVERY SETTING IS PER FILE.
 *
 * The format, the bitrate, the trim, the channels — all of it lives on the
 * row, not on the page. Somebody with four files usually wants four
 * different things, and a single global selector makes them convert in
 * batches and re-drop the same files. "Convert all to" and "apply to all"
 * exist for when they really are the same.
 * ─────────────────────────────────────────────────────────────────────
 */

import { FFmpeg } from '@ffmpeg/ffmpeg'
import { fetchFile } from '@ffmpeg/util'

const SAMPLE_RATE = 44100
const FLAC_RATIO = 0.6          // FLAC lands near 60% of 16-bit PCM

const humanBytes = (n) => {
    if (n === null || n === undefined || isNaN(n)) return '—'
    if (n < 1024) return `${Math.round(n)} B`
    if (n < 1024 * 1024) return `${(n / 1024).toFixed(0)} KB`
    if (n < 1024 * 1024 * 1024) return `${(n / 1024 / 1024).toFixed(1)} MB`
    return `${(n / 1024 / 1024 / 1024).toFixed(2)} GB`
}

const humanTime = (s) => {
    if (!s || !isFinite(s)) return '—'
    const h = Math.floor(s / 3600)
    const m = Math.floor((s % 3600) / 60)
    const r = Math.round(s % 60)
    if (h) return `${h}h ${m}m`
    return m > 0 ? `${m}m ${r}s` : `${r}s`
}

/**
 * "1:30", "90", "00:01:30" → 90.
 *
 * Deliberately forgiving. Somebody typing a trim point should not have to
 * learn a format, and every shape they might reasonably type means the same
 * unambiguous thing.
 */
function parseTime(value) {
    const raw = String(value ?? '').trim()
    if (!raw) return null

    const parts = raw.split(':').map((p) => Number(p))
    if (parts.some((p) => isNaN(p) || p < 0)) return null

    if (parts.length === 1) return parts[0]
    if (parts.length === 2) return parts[0] * 60 + parts[1]
    if (parts.length === 3) return parts[0] * 3600 + parts[1] * 60 + parts[2]
    return null
}

/** Seconds → HH:MM:SS, which is what ffmpeg's -ss and -to want. */
function toClock(seconds) {
    const s = Math.max(0, Math.floor(seconds))
    const h = String(Math.floor(s / 3600)).padStart(2, '0')
    const m = String(Math.floor((s % 3600) / 60)).padStart(2, '0')
    const r = String(s % 60).padStart(2, '0')
    return `${h}:${m}:${r}`
}

/**
 * How long the audio is, without decoding it.
 *
 * An <audio> element reads only the header, so this costs nothing. Calling
 * decodeAudioData instead would pull the entire file into memory as raw
 * samples — on a 100 MB input that is most of the memory budget spent
 * answering a question, before any work has started.
 *
 * Some containers refuse to report a duration (wma and amr in particular).
 * null is a valid answer, and the caller degrades to "we cannot promise the
 * output size" rather than refusing a file that would convert perfectly.
 */
function probeDuration(file) {
    return new Promise((resolve) => {
        const url = URL.createObjectURL(file)
        const el = new Audio()
        let settled = false

        const done = (value) => {
            if (settled) return
            settled = true
            URL.revokeObjectURL(url)
            resolve(value)
        }

        el.preload = 'metadata'
        el.onloadedmetadata = () => done(isFinite(el.duration) ? el.duration : null)
        el.onerror = () => done(null)
        // A container the browser cannot parse must not hang the queue.
        setTimeout(() => done(null), 5000)
        el.src = url
    })
}

/**
 * Is this really audio?
 *
 * THE EXTENSION IS NOT EVIDENCE. Anyone can rename holiday.jpg to
 * holiday.mp3, and the `accept` attribute on a file input is a filter for
 * the picker dialog that drag-and-drop ignores entirely. Handing a JPEG to
 * ffmpeg wastes the visitor's time and ends in an error that explains
 * nothing.
 *
 * So the first bytes are read and matched against the container signatures
 * of the formats this tool takes. Sixteen bytes, no decoding, instant.
 *
 * The list is what we ACCEPT, not everything that exists: video containers
 * are deliberately absent, because this converts audio and an MP4 of
 * somebody's holiday is not that.
 */
async function sniffAudio(file) {
    let head

    try {
        head = new Uint8Array(await file.slice(0, 16).arrayBuffer())
    } catch (e) {
        return false
    }

    if (head.length < 12) return false

    const at = (offset, text) => {
        for (let i = 0; i < text.length; i++) {
            if (head[offset + i] !== text.charCodeAt(i)) return false
        }
        return true
    }

    // MP3 — an ID3 tag, or a raw MPEG frame sync (11 bits set).
    if (at(0, 'ID3')) return true
    if (head[0] === 0xff && (head[1] & 0xe0) === 0xe0) return true

    if (at(0, 'RIFF') && at(8, 'WAVE')) return true              // WAV
    if (at(0, 'OggS')) return true                                // OGG · OPUS
    if (at(0, 'fLaC')) return true                                // FLAC
    if (at(4, 'ftyp')) return true                                // M4A · AAC
    if (at(0, 'FORM') && (at(8, 'AIFF') || at(8, 'AIFC'))) return true
    if (at(0, '#!AMR')) return true                               // AMR
    if (at(0, 'caff')) return true                                // CAF

    // ASF / WMA — a 16-byte GUID; the first four are enough to identify it.
    if (head[0] === 0x30 && head[1] === 0x26 && head[2] === 0xb2 && head[3] === 0x75) return true

    return false
}

/*
 * Registration timing.
 *
 * This entry is printed inside the page body, which puts it BEFORE
 * Livewire's bundle at the foot of the document — so alpine:init has not
 * fired yet and the listener below catches it. The second branch is there
 * because that ordering is an assumption about a layout this file does not
 * own, and an unregistered Alpine component fails SILENTLY: the markup
 * renders, nothing binds, and the page looks merely broken rather than
 * erroring.
 */
const register = () => window.Alpine.data('converter', (config) => ({
    cfg: config,

    /* ── Engine ── */
    ffmpeg: null,
    engine: 'idle',        // idle · loading · ready · failed
    engineError: '',       // what ACTUALLY went wrong, not a guess
    available: null,       // format keys this build can actually write
    hidden: [],            // ...and the ones it cannot, so we can SAY so
    isMobile: false,

    /* ── Queue ── */
    files: [],
    running: false,
    editing: null,         // id of the row whose settings panel is open
    bulk: '',              // "convert all to" selection
    locked: null,          // a pair page fixes the target; /converter does not
    flashing: [],          // ids pulsing green right after finishing

    init() {
        this.isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent)
    },

    /**
     * Fix the destination, for a /convert/{pair} page.
     *
     * Somebody who searched "wav to mp3" told us both halves before they
     * arrived. Leaving them a dropdown to pick MP3 is asking a question
     * they already answered, and the format controls disappear rather than
     * sitting there pre-filled — a control with one correct value is not a
     * control, it is furniture.
     */
    lockTo(key) {
        if (this.cfg.formats[key]) this.locked = key
    },

    /* ═══════════════════════ Formats ═══════════════════════ */

    get formatList() {
        return Object.entries(this.cfg.formats)
            .filter(([key]) => this.available === null || this.available.includes(key))
            .map(([key, f]) => ({ key, ...f }))
    },

    fmt(entry) {
        return this.cfg.formats[entry.target]
    },

    /**
     * What is missing, in the visitor's words.
     *
     * A format that vanishes with no explanation reads as a broken page:
     * somebody who came to make a WAV and finds no WAV concludes the tool
     * does not work, which is worse than telling them the truth in a line.
     */
    get hiddenLabels() {
        return this.hidden
            .map((k) => this.cfg.formats[k]?.label)
            .filter(Boolean)
            .join(', ')
    },

    /* ═══════════════════════ The engine ═══════════════════════ */

    /**
     * Fetch and start ffmpeg. Called on the FIRST file, never on load.
     *
     * IT CHECKS THE FILES BEFORE IT BLAMES THE BROWSER.
     *
     * The first version of this said "your browser does not support
     * WebAssembly" on any failure — a cause it had not checked, on a
     * feature every browser has had since 2017. It was wrong essentially
     * every time it appeared, and it sent whoever read it looking in the
     * one place the problem was not.
     *
     * A missing core file is by far the likeliest failure and the only one
     * with a one-line fix, so it is the first thing asked. Everything else
     * reports the real error text rather than a story about it.
     */
    async boot() {
        if (this.engine === 'ready' || this.engine === 'loading') return

        this.engine = 'loading'
        this.engineError = ''

        try {
            // Are the engine files actually there? A 404 here is a
            // deployment problem, not a browser problem, and saying so
            // saves somebody an afternoon in the wrong place.
            const probe = await fetch(`${this.cfg.core}/ffmpeg-core.js`, { method: 'HEAD' })

            if (!probe.ok) {
                this.engine = 'failed'
                this.engineError = `The engine is not installed. `
                    + `${this.cfg.core}/ffmpeg-core.js answered ${probe.status}.`
                return
            }

            const ff = new FFmpeg()

            ff.on('progress', ({ progress }) => {
                const active = this.files.find((f) => f.status === 'working')
                if (!active) return
                // ffmpeg.wasm reports nonsense on some inputs — values above
                // 1, or NaN. A bar that jumps to 4000% is worse than one
                // that simply keeps moving, so clamp and ignore rubbish.
                const p = Math.round(progress * 100)
                if (isFinite(p) && p >= 0) active.progress = Math.min(99, p)
            })

            /*
             * THE WORKER HAS TO COME FROM OUR OWN ORIGIN.
             *
             * @ffmpeg/ffmpeg builds its worker with
             *   new Worker(new URL('./worker.js', import.meta.url))
             *
             * In development import.meta.url points at the Vite dev server
             * — http://[::1]:5173 — while the page is served from the site's
             * own host. A Worker must be same-origin, so the browser refuses
             * it outright:
             *
             *   Failed to construct 'Worker': Script at
             *   'http://[::1]:5173/...worker.js' cannot be accessed from
             *   origin 'http://dbelo.test'.
             *
             * A production build does not have the problem, because the
             * worker is emitted into public/build alongside the page. But
             * "works only after npm run build" is a trap that costs an hour
             * every time somebody forgets, so we serve our own copy and use
             * it in both.
             *
             * IF THE COPY IS ABSENT we simply omit the option and let the
             * library use its bundled worker — which is correct in a
             * production build. Degrade, never crash.
             */
            /*
             * ABSOLUTE URLS, BUILT FROM THE PAGE'S OWN ORIGIN.
             *
             * A root-relative path is not enough. The library resolves what
             * it is given with `new URL(value, import.meta.url)` — and in
             * development import.meta.url is the Vite dev server, so
             * "/vendor/ffmpeg/worker/worker.js" came back out as
             * "http://[::1]:5173/vendor/ffmpeg/worker/worker.js" and the
             * browser refused it as cross-origin all over again.
             *
             * Resolving against location.origin here means the value is
             * already absolute by the time the library sees it, and a URL
             * that is already absolute has no base left to be resolved
             * against wrongly.
             */
            const here = (path) => new URL(path, window.location.origin).href

            const options = {
                coreURL: here(`${this.cfg.core}/ffmpeg-core.js`),
                wasmURL: here(`${this.cfg.core}/ffmpeg-core.wasm`),
            }

            const workerURL = here(`${this.cfg.core}/worker/worker.js`)
            const hasWorker = await fetch(workerURL, { method: 'HEAD' })
                .then((r) => r.ok)
                .catch(() => false)

            if (hasWorker) {
                options.classWorkerURL = workerURL
            } else {
                console.warn('[converter] no self-hosted worker at', workerURL,
                    '— falling back to the bundled one, which only works in a production build')
            }

            await ff.load(options)

            this.ffmpeg = ff
            await this.detectEncoders(ff)
            this.engine = 'ready'
        } catch (e) {
            console.error('[converter] engine failed', e)
            this.engine = 'failed'
            // The real message. Ugly, and infinitely more useful than a
            // sentence we made up about a cause we never tested.
            this.engineError = e?.message ? String(e.message) : String(e)
        }
    },

    /**
     * Ask the build what it can actually write, and hide the rest.
     *
     * Which encoders a wasm core ships with is not something to assume —
     * AAC in particular is subject to licensing and binary-size decisions
     * that vary between builds. Offering a format the engine cannot produce
     * means a failure at the end of the work instead of an option that was
     * never there.
     */
    async detectEncoders(ff) {
        const lines = []
        const collect = ({ message }) => lines.push(message)

        try {
            ff.on('log', collect)
            await ff.exec(['-hide_banner', '-encoders'])
            ff.off('log', collect)

            const text = lines.join('\n')
            const all = Object.keys(this.cfg.formats)
            const found = all.filter((key) =>
                new RegExp(`\\b${this.cfg.formats[key].encoder}\\b`).test(text))

            /*
             * A PARSE THAT FINDS ALMOST NOTHING DID NOT FIND THE TRUTH.
             *
             * If the log never arrived, or arrived in a shape this regex
             * does not read, `found` comes back tiny — and acting on that
             * would delete five working formats on the strength of a failed
             * read. Below half the list we assume the reading failed, not
             * the build.
             */
            if (found.length < Math.ceil(all.length / 2)) {
                console.warn('[converter] encoder list unreadable, offering everything')
                this.available = null
                this.hidden = []
                return
            }

            this.available = found
            this.hidden = all.filter((k) => !found.includes(k))

            if (this.hidden.length) {
                console.warn('[converter] not in this build:', this.hidden.join(', '))
                // A row already pointing at a format this build cannot write
                // has to move, or it would fail at the end of its work.
                this.files.forEach((f) => {
                    if (this.hidden.includes(f.target)) this.setTarget(f, found[0])
                })
            }
        } catch (e) {
            console.warn('[converter] could not read the encoder list', e)
            this.available = null
            this.hidden = []
        }
    },

    /* ═══════════════════════ The queue ═══════════════════════ */

    /** A sensible destination for a file, given what it already is. */
    defaultTarget(name) {
        const ext = (name.split('.').pop() || '').toLowerCase()
        // Landing on the format you already have would be a no-op, so a WAV
        // defaults to MP3 and everything else defaults to WAV — which are
        // the two directions almost everybody actually wants.
        if (['wav', 'aiff', 'aif', 'flac'].includes(ext)) return 'mp3'
        return 'wav'
    },

    async add(fileList) {
        const incoming = Array.from(fileList || [])
        if (!incoming.length) return

        const room = this.cfg.maxFiles - this.files.length

        for (const file of incoming.slice(0, Math.max(0, room))) {
            /*
             * TWO GATES, AND A REJECTED FILE STILL APPEARS IN THE LIST.
             *
             * Dropping a photo and having nothing happen is the worst
             * possible report: it is indistinguishable from a broken page,
             * and the visitor tries again. It gets a row that says what it
             * is and why it cannot be converted.
             */
            const ext = (file.name.split('.').pop() || '').toLowerCase()
            const known = this.cfg.accepts.includes(`.${ext}`)
            const real = known ? await sniffAudio(file) : false

            if (!known || !real) {
                this.files.push({
                    id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
                    file, name: file.name, size: file.size, duration: null,
                    target: 'mp3', rate: 192, codec: null,
                    channels: '', frequency: '', volume: '', cutFrom: '', cutTo: '',
                    status: 'rejected',
                    progress: 0,
                    message: known
                        // Right extension, wrong contents — a renamed file.
                        ? 'This is not an audio file. The name ends in .' + ext
                          + ', but the contents are something else.'
                        : 'Only audio can be converted here — images, video and documents are not accepted.',
                    detail: '',
                    url: null, outName: '', outSize: 0,
                })
                continue
            }

            const target = this.locked ?? this.defaultTarget(file.name)
            const format = this.cfg.formats[target]

            const entry = {
                id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
                file,
                name: file.name,
                size: file.size,
                duration: null,

                /* Per-file settings — every one of them */
                target,
                rate: format.default,
                codec: Object.keys(format.codecs)[0] ?? null,
                channels: '',
                frequency: '',
                volume: '',
                cutFrom: '',
                cutTo: '',

                status: 'queued',   // queued · working · done · error · blocked · rejected
                /*
                 * The duration arrives asynchronously, and "still reading
                 * it" is a different state from "this file will never tell
                 * us". Collapsing both into a dash was the bug: a dash sits
                 * there looking permanent while the answer is two hundred
                 * milliseconds away, and looks identical when the answer is
                 * never coming.
                 */
                probing: true,
                progress: 0,
                message: '',
                detail: '',         // the raw engine error, for the curious
                url: null,
                outName: '',
                outSize: 0,
            }

            if (file.size > this.cfg.maxInput) {
                entry.status = 'blocked'
                entry.message = `Too big — a browser tab holds about ${humanBytes(this.cfg.maxInput)}.`
            }

            this.files.push(entry)

            if (entry.status !== 'blocked') {
                probeDuration(file).then((d) => {
                    entry.duration = d
                    entry.probing = false
                    this.check(entry)
                })
            }
        }

        // Booting on the first file, not on page load, is what keeps the
        // page light enough to rank.
        this.boot()
    },

    remove(id) {
        const entry = this.files.find((f) => f.id === id)
        if (entry?.url) URL.revokeObjectURL(entry.url)
        this.files = this.files.filter((f) => f.id !== id)
        if (this.editing === id) this.editing = null
    },

    clear() {
        this.files.forEach((f) => f.url && URL.revokeObjectURL(f.url))
        this.files = []
        this.editing = null
    },

    /* ═══════════════════════ Settings ═══════════════════════ */

    /**
     * Point a row at a different format — including one already converted.
     *
     * A finished row used to be frozen, so wanting the same file as MP3
     * after taking it as WAV meant deleting it and dropping it again. That
     * is the tool telling somebody their own file is finished with, which
     * it is not.
     *
     * CHANGING THE TARGET DISCARDS THE OLD RESULT, on purpose. A Download
     * button handing over a WAV while the select beside it says MP3 is a
     * control that lies, and the row visibly falling back from DONE to
     * READY is the honest signal that there is work to redo.
     */
    setTarget(entry, key) {
        const format = this.cfg.formats[key]
        if (!format) return

        entry.target = key
        entry.rate = format.default
        entry.codec = Object.keys(format.codecs)[0] ?? null

        this.reopen(entry)
        this.check(entry)
    },

    /** Send a finished row back to the queue, releasing its result. */
    reopen(entry) {
        if (entry.status !== 'done') return

        if (entry.url) URL.revokeObjectURL(entry.url)

        entry.url = null
        entry.outSize = 0
        entry.outName = ''
        entry.progress = 0
        entry.status = 'queued'
        entry.message = ''
    },

    /** "Convert all to" — the shortcut for when they really are the same. */
    setAllTargets(key) {
        if (!key) return
        // Only a row being converted RIGHT NOW is off limits. A finished
        // one is re-aimed and queued again, which is what somebody pressing
        // "convert all to" after a batch plainly means.
        this.files.forEach((f) => {
            if (f.status !== 'working') this.setTarget(f, key)
        })
        this.bulk = ''
    },

    /**
     * Copy one row's settings onto every other row.
     *
     * The format goes too. In the reference this is worded "apply to all
     * conversions", and somebody who ticks it after choosing WAV at 24-bit
     * means all of it — copying the bit depth but not the format would be
     * the surprising half.
     */
    applyToAll(entry) {
        this.files.forEach((f) => {
            if (f.id === entry.id) return
            if (f.status === 'working') return

            this.reopen(f)

            f.target = entry.target
            f.rate = entry.rate
            f.codec = entry.codec
            f.channels = entry.channels
            f.frequency = entry.frequency
            f.volume = entry.volume
            f.cutFrom = entry.cutFrom
            f.cutTo = entry.cutTo
            this.check(f)
        })
    },

    /* ═══════════════════════ The size guard ═══════════════════════ */

    /**
     * Bytes of output per second of audio.
     *
     * Explicit per kind, with no fall-through: a future lossless format
     * added to Converter::FORMATS must not be estimated as if it were
     * lossy — that would report a few megabytes for something that comes
     * out at several hundred, and let the tab die on a file the tool had
     * just promised would fit.
     */
    bytesPerSecond(entry) {
        const f = this.fmt(entry)
        const channels = entry.channels === '1' ? 1 : 2
        const rate = Number(entry.frequency) || SAMPLE_RATE

        switch (f.kind) {
            case 'pcm': {
                const depth = f.codecs[entry.codec]?.bytes ?? 2
                return rate * depth * channels
            }
            case 'flac':
                return rate * 2 * channels * FLAC_RATIO
            case 'lossy':
                return (entry.rate * 1000) / 8
            default:
                // An unknown kind must not silently under-estimate.
                // Assuming the worst refuses a big file rather than
                // accepting one that cannot finish.
                console.warn('[converter] unknown format kind', f.kind)
                return rate * 4 * 2
        }
    },

    /** Duration after the trim — the number the estimate must use. */
    effectiveDuration(entry) {
        if (!entry.duration) return null

        const from = parseTime(entry.cutFrom) ?? 0
        const to = parseTime(entry.cutTo)
        const end = to !== null ? Math.min(to, entry.duration) : entry.duration

        return Math.max(0, end - from)
    },

    outputBytes(entry) {
        const seconds = this.effectiveDuration(entry)
        if (seconds === null) return null
        return Math.round(seconds * this.bytesPerSecond(entry))
    },

    /**
     * Will the RESULT fit? The check a size cap alone misses.
     *
     * Trimming counts: cutting a two-hour podcast to ten minutes is exactly
     * how somebody legitimately gets a WAV out of a file that could never
     * have produced one whole.
     */
    check(entry) {
        if (entry.status === 'done' || entry.status === 'working') return
        if (entry.size > this.cfg.maxInput) return   // already blocked, other reason

        // No duration means no promise. Let it run and find out — refusing
        // a container we simply could not read would reject files that
        // convert perfectly well.
        if (!entry.duration) {
            if (entry.status === 'blocked') { entry.status = 'queued'; entry.message = '' }
            return
        }

        const bytes = this.outputBytes(entry)

        if (bytes > this.cfg.maxOutput) {
            const maxSeconds = this.cfg.maxOutput / this.bytesPerSecond(entry)
            entry.status = 'blocked'
            entry.message = `${humanTime(this.effectiveDuration(entry))} as `
                + `${this.fmt(entry).label} would be about ${humanBytes(bytes)}, which no browser tab can hold. `
                + `Trim it to about ${humanTime(maxSeconds)}, or pick a smaller format.`
        } else if (entry.status === 'blocked') {
            entry.status = 'queued'
            entry.message = ''
        }
    },

    /* ═══════════════════════ Running ═══════════════════════ */

    get pending() {
        return this.files.filter((f) => f.status === 'queued')
    },

    /** Rows that could ever be converted — a rejected one never can. */
    get eligible() {
        return this.files.filter((f) => f.status !== 'blocked' && f.status !== 'rejected')
    },

    /** True once a batch has finished and there is something to take. */
    get finished() {
        return !this.running && this.pending.length === 0 && this.done.length > 0
    },

    get done() {
        return this.files.filter((f) => f.status === 'done')
    },

    /** Overall progress across the batch, for the bar at the bottom. */
    get overall() {
        const eligible = this.eligible
        if (!eligible.length) return 0

        const total = eligible.reduce((sum, f) => {
            if (f.status === 'done') return sum + 100
            if (f.status === 'error') return sum + 100
            if (f.status === 'working') return sum + f.progress
            return sum
        }, 0)

        return Math.round(total / eligible.length)
    },

    async run() {
        if (this.running) return
        if (this.engine !== 'ready') await this.boot()
        if (this.engine !== 'ready') return

        this.editing = null
        this.running = true

        // ONE AT A TIME, on purpose. Ten conversions in parallel is ten
        // copies of the audio in memory, which is how a tab dies.
        for (const entry of this.files.filter((f) => f.status === 'queued')) {
            await this.convert(entry)
        }

        this.running = false
    },

    /** The ffmpeg command for one row, built from its own settings. */
    argsFor(entry, inName, outName) {
        const f = this.fmt(entry)
        const args = ['-i', inName]

        /*
         * -ss and -to go AFTER -i on purpose.
         *
         * Before the input they are a fast but approximate seek, and -to
         * then means something different depending on the ffmpeg version.
         * After it they are accurate and absolute in the input's timeline,
         * which is what somebody typing "1:30 to 2:00" means. The extra
         * decoding costs nothing on audio.
         */
        const from = parseTime(entry.cutFrom)
        const to = parseTime(entry.cutTo)
        if (from !== null && from > 0) args.push('-ss', toClock(from))
        if (to !== null && to > 0) args.push('-to', toClock(to))

        // Drop cover art or any video stream. Without this an MP3 with
        // embedded artwork can fail outright, or carry the image into a
        // container that cannot hold it.
        args.push('-vn')

        if (f.kind === 'pcm') {
            args.push('-codec:a', entry.codec || f.encoder)
        } else if (f.kind === 'flac') {
            args.push('-codec:a', 'flac')
        } else {
            args.push('-codec:a', f.encoder, '-b:a', `${entry.rate}k`)
        }

        if (entry.frequency) args.push('-ar', entry.frequency)
        if (entry.channels) args.push('-ac', entry.channels)
        if (entry.volume) args.push('-filter:a', `volume=${entry.volume}`)

        args.push(outName)
        return args
    },

    async convert(entry) {
        entry.status = 'working'
        entry.progress = 0
        entry.message = ''

        const inName = `in-${entry.id}`
        const outName = `out-${entry.id}.${this.fmt(entry).ext}`

        try {
            await this.ffmpeg.writeFile(inName, await fetchFile(entry.file))
            await this.ffmpeg.exec(this.argsFor(entry, inName, outName))

            const data = await this.ffmpeg.readFile(outName)
            const blob = new Blob([data.buffer], { type: 'application/octet-stream' })

            entry.url = URL.createObjectURL(blob)
            entry.outSize = blob.size
            entry.outName = `${entry.name.replace(/\.[^.]+$/, '') || 'audio'}.${this.fmt(entry).ext}`
            entry.progress = 100
            entry.status = 'done'

            /*
             * A brief green pulse on the row that just finished.
             *
             * In a queue of ten, "one more is ready" is otherwise a badge
             * quietly changing four rows down while you are looking
             * somewhere else. Motion is what the eye actually notices, and
             * a second of it is enough — anything longer becomes decoration
             * and stops meaning "this just happened".
             */
            this.flashing.push(entry.id)
            setTimeout(() => {
                this.flashing = this.flashing.filter((id) => id !== entry.id)
            }, 1400)
        } catch (e) {
            console.error('[converter] failed', entry.name, e)
            entry.status = 'error'
            entry.message = 'This file could not be converted.'
            // The real text, kept separately so the row can show a sentence
            // a person understands AND the engine's own words underneath,
            // without one crowding the other out of the layout.
            entry.detail = e?.message ? String(e.message) : String(e)
        } finally {
            /*
             * ALWAYS, including after a failure.
             *
             * ffmpeg.wasm keeps its virtual filesystem in memory: leaving
             * one 100 MB input behind means the next file starts with that
             * much less room, and a queue that works at file one fails at
             * file four for reasons nobody can see.
             */
            try { await this.ffmpeg.deleteFile(inName) } catch (_) {}
            try { await this.ffmpeg.deleteFile(outName) } catch (_) {}
        }
    },

    /* ═══════════════════════ For the template ═══════════════════════ */

    /**
     * Hand over every finished file, one after another.
     *
     * Staggered on purpose: a browser that receives ten download calls in
     * the same tick treats it as a pop-up storm and silently drops all but
     * the first. A quarter of a second apart, every one arrives.
     */
    async downloadAll() {
        for (const entry of this.done) {
            const a = document.createElement('a')
            a.href = entry.url
            a.download = entry.outName
            document.body.appendChild(a)
            a.click()
            a.remove()
            await new Promise((r) => setTimeout(r, 250))
        }
    },

    /** Empty the queue and go back to the drop zone. */
    reset() {
        this.clear()
        this.flashing = []
        this.bulk = ''
    },

    flashed(entry) {
        return this.flashing.includes(entry.id)
    },

    badge(entry) {
        switch (entry.status) {
            case 'queued': return { text: 'READY', tone: 'ready' }
            case 'working': return { text: 'CONVERTING', tone: 'working' }
            case 'done': return { text: 'DONE', tone: 'done' }
            case 'error': return { text: 'FAILED', tone: 'error' }
            case 'blocked': return { text: 'TOO BIG', tone: 'blocked' }
            case 'rejected': return { text: 'NOT AUDIO', tone: 'error' }
            default: return { text: '', tone: 'ready' }
        }
    },

    /** Above this on a phone, warn — never block. */
    warnMobile(entry) {
        return this.isMobile && entry.size > this.cfg.mobileWarn
    },

    /**
     * The predicted output size, or the reason there is not one yet.
     *
     * Three answers, not two. A file whose metadata is still loading and a
     * file whose length can never be read look the same in a size column,
     * and they are not the same thing at all — the first resolves on its
     * own, the second wants explaining.
     */
    estimateFor(entry) {
        if (entry.probing) return null
        const bytes = this.outputBytes(entry)
        return bytes === null ? null : humanBytes(bytes)
    },

    /** Why the size column is not showing a size. */
    estimateNote(entry) {
        if (entry.probing) return 'Reading the length of this file…'
        if (!entry.duration) {
            return 'The length of this file could not be read, so the result size '
                + 'cannot be predicted. It will still convert.'
        }
        return ''
    },

    trimmed(entry) {
        return Boolean(parseTime(entry.cutFrom) || parseTime(entry.cutTo))
    },

    bytes: humanBytes,
    time: humanTime,
}))

if (window.Alpine) {
    register()
} else {
    document.addEventListener('alpine:init', register)
}
