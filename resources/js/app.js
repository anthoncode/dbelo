import {
    Chart,
    LineController, BarController, DoughnutController,
    LineElement, PointElement, BarElement, ArcElement,
    CategoryScale, LinearScale,
    Filler, Tooltip,
} from 'chart.js'

// Only what the admin charts actually draw. Registering the whole library
// pulls in every controller and scale type for the sake of three chart
// shapes, and this bundle is loaded on the public site too.
Chart.register(
    LineController, BarController, DoughnutController,
    LineElement, PointElement, BarElement, ArcElement,
    CategoryScale, LinearScale,
    Filler, Tooltip,
)

// The design tokens, so a chart can never drift from the rest of the panel.
const PALETTE = {
    brand: '#a32eb7',
    action: '#f9510f',
    info: '#03a3e1',
    success: '#89d206',
    warning: '#ffa314',
    danger: '#f90f3b',
}

const INK = 'rgba(245, 244, 250, 0.45)'
const GRID = 'rgba(245, 244, 250, 0.06)'

/**
 * A vertical fade from the series colour down to nothing.
 *
 * Built against the canvas rather than as a CSS gradient because Chart.js
 * fills with a canvas paint object — and it has to be rebuilt whenever the
 * chart resizes, since the gradient is defined in pixels.
 */
function fade(ctx, area, hex, strength = 0.35) {
    if (!area) return hexToRgba(hex, strength)

    const gradient = ctx.createLinearGradient(0, area.top, 0, area.bottom)
    gradient.addColorStop(0, hexToRgba(hex, strength))
    gradient.addColorStop(0.6, hexToRgba(hex, strength * 0.35))
    gradient.addColorStop(1, hexToRgba(hex, 0))

    return gradient
}

function hexToRgba(hex, alpha) {
    const n = parseInt(hex.slice(1), 16)
    return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`
}

const tooltip = {
    backgroundColor: '#f5f4fa',
    titleColor: '#1f1d33',
    bodyColor: '#1f1d33',
    borderWidth: 0,
    padding: 12,
    cornerRadius: 10,
    displayColors: false,
    titleFont: { family: 'Outfit, sans-serif', size: 12, weight: '500' },
    bodyFont: { family: 'Outfit, sans-serif', size: 14, weight: '600' },
}

function lineConfig(labels, values, colour) {
    return {
        type: 'line',
        data: {
            labels,
            datasets: [{
                data: values,
                borderColor: colour,
                borderWidth: 2.5,
                // A function rather than a value: Chart.js calls it again on
                // every resize, which is when the gradient needs rebuilding.
                backgroundColor: (context) => fade(context.chart.ctx, context.chart.chartArea, colour),
                fill: true,
                tension: 0.35,
                pointRadius: 0,
                pointHoverRadius: 5,
                pointHoverBackgroundColor: colour,
                pointHoverBorderColor: '#1f1d33',
                pointHoverBorderWidth: 3,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { tooltip },
            scales: {
                x: {
                    grid: { display: false },
                    border: { display: false },
                    ticks: { color: INK, font: { size: 11 }, maxRotation: 0, autoSkipPadding: 24 },
                },
                y: {
                    beginAtZero: true,
                    grid: { color: GRID },
                    border: { display: false },
                    ticks: { color: INK, font: { size: 11 }, precision: 0, maxTicksLimit: 5 },
                },
            },
        },
    }
}

function barConfig(labels, values, colour, horizontal = false) {
    return {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                data: values,
                backgroundColor: (context) => fade(context.chart.ctx, context.chart.chartArea, colour, 0.9),
                hoverBackgroundColor: colour,
                borderRadius: 6,
                borderSkipped: false,
                barPercentage: 0.7,
                categoryPercentage: 0.8,
            }],
        },
        options: {
            indexAxis: horizontal ? 'y' : 'x',
            responsive: true,
            maintainAspectRatio: false,
            plugins: { tooltip },
            scales: {
                x: {
                    grid: { display: horizontal, color: GRID },
                    border: { display: false },
                    ticks: { color: INK, font: { size: 11 }, precision: 0 },
                },
                y: {
                    beginAtZero: true,
                    grid: { display: !horizontal, color: GRID },
                    border: { display: false },
                    ticks: { color: INK, font: { size: 11 }, precision: 0 },
                },
            },
        },
    }
}

function doughnutConfig(labels, values, colours) {
    return {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data: values,
                backgroundColor: colours,
                borderWidth: 0,
                hoverOffset: 8,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '68%',
            plugins: { tooltip },
        },
    }
}

/**
 * Alpine glue.
 *
 * The canvas sits inside wire:ignore, because a Livewire re-render replaces
 * the DOM node and Chart.js loses the context it was drawing on — the chart
 * silently goes blank. Instead the component listens for an event carrying
 * new data and calls update(), which keeps the animation and the instance.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('dbeloChart', (config) => ({
        chart: null,

        init() {
            const colour = PALETTE[config.colour] ?? PALETTE.brand
            const canvas = this.$refs.canvas.getContext('2d')

            const build = {
                line: () => lineConfig(config.labels, config.values, colour),
                bar: () => barConfig(config.labels, config.values, colour, config.horizontal),
                doughnut: () => doughnutConfig(
                    config.labels,
                    config.values,
                    (config.colours ?? ['brand', 'info', 'success', 'warning', 'action', 'danger'])
                        .map((name) => PALETTE[name] ?? name),
                ),
            }[config.type ?? 'line']

            this.chart = new Chart(canvas, build())

            window.addEventListener(`chart:${config.id}`, (event) => {
                this.chart.data.labels = event.detail.labels
                this.chart.data.datasets[0].data = event.detail.values
                this.chart.update()
            })

            // Livewire tears the page down on navigate; without this the
            // canvas leaks and the next visit starts with a dead instance.
            document.addEventListener('livewire:navigating', () => this.chart?.destroy(), { once: true })
        },
    }))
})

/* ============================================================
   The player
   ============================================================

   One <Audio> for the whole site, created here and never placed in the
   DOM. That is the point: `wire:navigate` swaps the document body, and any
   audio element living inside it would be destroyed mid-note. An object
   held on `window` cannot be swapped away, so the sound keeps playing while
   the visitor keeps browsing — which is the entire reason a sound library
   needs a persistent player at all.

   Everything talks through window events rather than shared references, so
   a row rendered by Livewire five navigations from now still works without
   knowing anything about the bar:

     dbelo:play   a row asks for a track      → { id, src, title, ... }
     dbelo:seek   a row was clicked mid-wave  → { id, ratio, track }
     dbelo:state  the player broadcasting     → the whole state, ~4×/second
   ============================================================ */

function createPlayer() {
    const audio = new Audio()
    audio.preload = 'none'

    const state = {
        track: null,
        playing: false,
        progress: 0,
        current: 0,
        duration: 0,
        volume: 1,
    }

    const emit = () => window.dispatchEvent(
        new CustomEvent('dbelo:state', { detail: { ...state } })
    )

    audio.addEventListener('timeupdate', () => {
        state.current = audio.currentTime
        state.duration = audio.duration || state.track?.duration || 0
        state.progress = state.duration ? state.current / state.duration : 0
        emit()
    })

    audio.addEventListener('play', () => { state.playing = true; emit() })
    audio.addEventListener('pause', () => { state.playing = false; emit() })

    audio.addEventListener('ended', () => {
        state.playing = false
        state.progress = 0
        state.current = 0
        emit()
    })

    // A missing preview must not leave the bar spinning forever.
    audio.addEventListener('error', () => { state.playing = false; emit() })

    function load(track, ratio = 0) {
        const same = state.track && state.track.id === track.id

        if (! same) {
            state.track = track
            state.duration = track.duration || 0
            state.progress = 0
            state.current = 0
            audio.src = track.src
        }

        if (ratio) {
            const seekTo = () => { audio.currentTime = ratio * (audio.duration || track.duration || 0) }
            // A fresh src has no duration yet; wait for it rather than
            // setting currentTime on a zero-length track and losing the seek.
            audio.readyState > 0 ? seekTo() : audio.addEventListener('loadedmetadata', seekTo, { once: true })
        }

        audio.play().catch(() => { state.playing = false; emit() })
        emit()
    }

    function toggle(track) {
        if (state.track && state.track.id === track.id && state.playing) {
            audio.pause()

            return
        }

        load(track)
    }

    window.addEventListener('dbelo:play', (event) => toggle(event.detail))

    window.addEventListener('dbelo:seek', (event) => {
        const { id, ratio, track } = event.detail

        if (state.track && state.track.id === id) {
            const duration = audio.duration || state.duration
            if (duration) audio.currentTime = ratio * duration

            if (! state.playing) audio.play().catch(() => {})

            return
        }

        load(track, ratio)
    })

    // Space plays and pauses, unless something is being typed into.
    window.addEventListener('keydown', (event) => {
        if (event.code !== 'Space' || ! state.track) return

        const tag = document.activeElement?.tagName
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return
        if (document.activeElement?.isContentEditable) return

        event.preventDefault()
        toggle(state.track)
    })

    return {
        snapshot: () => ({ ...state }),
        toggle,
        stop() {
            audio.pause()
            audio.currentTime = 0
            state.track = null
            state.playing = false
            state.progress = 0
            state.current = 0
            emit()
        },
        seekRatio(ratio) {
            const duration = audio.duration || state.duration
            if (duration) audio.currentTime = ratio * duration
        },
        setVolume(value) {
            audio.volume = value
            state.volume = value
            emit()
        },
    }
}

// Created once per page load. `wire:navigate` never re-evaluates modules,
// and the ||= makes a second evaluation harmless if anything ever does.
const dbeloPlayer = (window.__dbeloPlayer ||= createPlayer())

function formatTime(seconds) {
    if (! seconds || isNaN(seconds)) return '0:00'

    const minutes = Math.floor(seconds / 60)
    const rest = Math.floor(seconds % 60).toString().padStart(2, '0')

    return `${minutes}:${rest}`
}

document.addEventListener('alpine:init', () => {
    /* The bar itself. A view over the player, holding no audio of its own. */
    window.Alpine.data('dbeloBar', () => ({
        track: null,
        playing: false,
        progress: 0,
        current: 0,
        duration: 0,
        volume: 1,

        init() {
            // Re-read on every init: without @persist the bar is rebuilt on
            // navigation, and it must come back showing what is playing
            // rather than empty.
            Object.assign(this, dbeloPlayer.snapshot())

            this.listener = (event) => Object.assign(this, event.detail)
            window.addEventListener('dbelo:state', this.listener)
        },

        destroy() {
            window.removeEventListener('dbelo:state', this.listener)
        },

        toggle() { if (this.track) dbeloPlayer.toggle(this.track) },
        close() { dbeloPlayer.stop() },
        setVolume(value) { dbeloPlayer.setVolume(value) },

        seek(event) {
            const rect = event.currentTarget.getBoundingClientRect()
            dbeloPlayer.seekRatio((event.clientX - rect.left) / rect.width)
        },

        format: formatTime,
    }))

    /* One row in a list, or the big player on a sound page. */
    window.Alpine.data('dbeloTrack', (track) => ({
        track,
        playing: false,
        progress: 0,

        init() {
            this.sync(dbeloPlayer.snapshot())

            this.listener = (event) => this.sync(event.detail)
            window.addEventListener('dbelo:state', this.listener)
        },

        destroy() {
            window.removeEventListener('dbelo:state', this.listener)
        },

        /**
         * Every row hears every tick; only the playing one reacts.
         *
         * The id comparison first means the other twenty-three rows do an
         * integer check and stop. Assigning the same value twice is free —
         * Alpine's reactivity drops a set that does not change anything.
         */
        sync(state) {
            const mine = state.track && state.track.id === this.track.id

            this.playing = mine && state.playing
            this.progress = mine ? state.progress : 0
        },

        toggle() { dbeloPlayer.toggle(this.track) },

        seek(event) {
            const rect = event.currentTarget.getBoundingClientRect()
            const ratio = (event.clientX - rect.left) / rect.width

            window.dispatchEvent(new CustomEvent('dbelo:seek', {
                detail: { id: this.track.id, ratio, track: this.track },
            }))
        },
    }))
})
