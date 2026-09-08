<?php

namespace App\Support;

/**
 * The free audio converter — what it accepts, what it produces, and where
 * it refuses.
 *
 * IT RUNS IN THE VISITOR'S BROWSER. There is no upload endpoint anywhere in
 * this application: ffmpeg is compiled to WebAssembly and executes inside
 * their tab, so the file never reaches this server. That is what makes the
 * module safe to run on shared hosting, and it is the one thing the page
 * can promise that its competitors cannot.
 *
 * NOTHING HERE PROTECTS THE SERVER, because the server does not
 * participate. Every limit below protects the VISITOR'S memory, and the
 * page says so in those words.
 *
 * ─────────────────────────────────────────────────────────────────────
 * THE TARGET IS PER FILE, NOT PER PAGE.
 *
 * Somebody arriving with four files usually wants four different things —
 * that WAV to OGG, those two MP3s to WAV. A single global selector forces
 * them to convert in batches and re-drop the same files, which is the shape
 * of a demo rather than a tool. Each row carries its own format and its own
 * settings; "Convert all to" is the shortcut for when they really are all
 * the same.
 * ─────────────────────────────────────────────────────────────────────
 */
class Converter
{
    /* ═══════════════════════════ The limits ═══════════════════════════ */

    /**
     * The file somebody drops in.
     *
     * ffmpeg.wasm loads the whole input into the tab's memory and then needs
     * room to work and room for the output on top. Reported practical
     * ceilings are 100–200 MB on a desktop INCLUDING all of that, so 100 MB
     * of input is the honest edge rather than a cautious one.
     *
     * What it covers: a podcast MP3 at 128 kbps runs about 58 MB an hour, so
     * this is nearly two hours. What it excludes is a raw WAV master — and
     * whoever holds one of those has real software.
     */
    public const MAX_INPUT_BYTES = 100 * 1024 * 1024;

    /**
     * THE LIMIT THAT ACTUALLY BITES, and the one a size cap alone misses.
     *
     * Converting TO a lossless format multiplies the size. A 100 MB MP3 at
     * 128 kbps is 104 minutes of audio, and 104 minutes of 16-bit WAV is
     * over a gigabyte — which fits in no browser tab, however small the
     * input was.
     *
     * So the tool estimates the OUTPUT from the duration, the target format
     * and the trim, and says so before the visitor waits. Being told "this
     * would come out at 1.1 GB, the most that fits is about 14 minutes" up
     * front beats a tab that dies three minutes in.
     */
    public const MAX_OUTPUT_BYTES = 150 * 1024 * 1024;

    /** One at a time, because ten at once kills the tab. */
    public const MAX_FILES = 10;

    /**
     * Above this, a phone gets a warning — not a block.
     *
     * Mobile Safari has a much lower ceiling and kills tabs without
     * ceremony. Warning and letting them decide respects the person holding
     * a phone that may well be fine; blocking at a number we guessed does
     * not.
     */
    public const MOBILE_WARN_BYTES = 40 * 1024 * 1024;

    /** Where the self-hosted core lives. Never a CDN. */
    public const CORE_PATH = '/vendor/ffmpeg';

    /* ═══════════════════════════ Formats ═══════════════════════════ */

    /**
     * What the file picker advertises.
     *
     * AUDIO CONTAINERS ONLY. mp4, webm and 3gp were here and came out: they
     * are video containers that happen to carry an audio track, and this is
     * a converter for audio. Leaving them in would also make the byte-level
     * check ambiguous, because a video MP4 and an M4A share the same `ftyp`
     * signature and only the brand inside tells them apart.
     *
     * Within that limit, accepting broadly costs nothing — ffmpeg reads far
     * more than it writes well — and it is what people search for.
     */
    public const ACCEPTS = [
        'mp3', 'wav', 'ogg', 'oga', 'flac', 'm4a', 'aac', 'opus',
        'aiff', 'aif', 'wma', 'amr', 'caf',
    ];

    /**
     * What it writes. Six, and the shortness is the point.
     *
     * WMA and AMR are deliberately absent: their encoders are poor, and a
     * conversion that sounds bad is worse than one you did not offer. AIFF
     * is absent because WAV covers the same need and everything opens it.
     *
     * `kind` drives both the ffmpeg arguments and the size estimate:
     *
     *   lossy → the bitrate dropdown; bytes/s = bitrate ÷ 8
     *   pcm   → the bit-depth dropdown; bytes/s = 44100 × depth × channels
     *   flac  → no choice; roughly 60% of 16-bit PCM
     *
     * EVERY LOSSY FORMAT USES -b:a rather than a per-codec quality scale.
     * Vorbis and Opus have nicer variable-rate modes, and using them would
     * make the output size unpredictable — which is precisely the number
     * this tool has to promise before it starts.
     *
     * @var array<string, array<string, mixed>>
     */
    public const FORMATS = [
        'mp3' => [
            'label' => 'MP3',
            'ext' => 'mp3',
            'kind' => 'lossy',
            'encoder' => 'libmp3lame',
            'rates' => [320, 256, 192, 128, 96],
            'default' => 192,
            'codecs' => [],
            'blurb' => 'Opens everywhere, on everything.',
        ],

        'wav' => [
            'label' => 'WAV',
            'ext' => 'wav',
            'kind' => 'pcm',
            'encoder' => 'pcm_s16le',
            'rates' => [],
            'default' => null,
            /*
             * Bit depth is WAV's quality control, and it is the one the
             * estimate has to know about: 24-bit is half again the size of
             * 16-bit for the same audio, and 32-bit float is double.
             */
            'codecs' => [
                'pcm_s16le' => ['label' => '16-bit — CD quality', 'bytes' => 2],
                'pcm_s24le' => ['label' => '24-bit — studio', 'bytes' => 3],
                'pcm_f32le' => ['label' => '32-bit float', 'bytes' => 4],
            ],
            'blurb' => 'Uncompressed, for editing. About 10 MB a minute.',
        ],

        'ogg' => [
            'label' => 'OGG',
            'ext' => 'ogg',
            'kind' => 'lossy',
            'encoder' => 'libvorbis',
            'rates' => [320, 256, 192, 128, 96],
            'default' => 192,
            'codecs' => [],
            'blurb' => 'Free and open, smaller than MP3 at the same quality.',
        ],

        'flac' => [
            'label' => 'FLAC',
            'ext' => 'flac',
            'kind' => 'flac',
            'encoder' => 'flac',
            'rates' => [],
            'default' => null,
            'codecs' => [],
            'blurb' => 'Lossless, about half the size of WAV.',
        ],

        'm4a' => [
            'label' => 'M4A',
            'ext' => 'm4a',
            'kind' => 'lossy',
            'encoder' => 'aac',
            'rates' => [256, 192, 128, 96],
            'default' => 192,
            'codecs' => [],
            'blurb' => 'What Apple devices and video editors expect.',
        ],

        'opus' => [
            'label' => 'OPUS',
            'ext' => 'opus',
            'kind' => 'lossy',
            'encoder' => 'libopus',
            'rates' => [192, 128, 96, 64],
            'default' => 128,
            'codecs' => [],
            'blurb' => 'The best sound per kilobyte there is.',
        ],
    ];

    /* ═══════════════════════ Per-file settings ═══════════════════════ */

    /** Leaving it alone is the default: resampling only ever loses. */
    public const FREQUENCIES = [
        '' => 'Auto (no change)',
        '48000' => '48 kHz — video, broadcast',
        '44100' => '44.1 kHz — CD, music',
        '32000' => '32 kHz',
        '22050' => '22.05 kHz',
    ];

    public const CHANNELS = [
        '' => 'Auto (no change)',
        '1' => 'Mono — halves the size of speech',
        '2' => 'Stereo',
    ];

    /**
     * Fixed steps in decibels, not a free number.
     *
     * A box that takes any value invites "300", and the answer to that is a
     * file that clips into noise. These are the adjustments somebody
     * actually wants, and none of them can ruin the audio outright.
     */
    public const VOLUMES = [
        '' => 'No change',
        '6dB' => 'Much louder (+6 dB)',
        '3dB' => 'Louder (+3 dB)',
        '-3dB' => 'Quieter (−3 dB)',
        '-6dB' => 'Much quieter (−6 dB)',
    ];

    /* ═══════════════════════════ For the page ═══════════════════════════ */

    /**
     * Everything the browser needs, in one payload.
     *
     * Shipped into the page rather than fetched: it is a literal array, and
     * a tool whose own settings arrive over the network is a tool that is
     * broken when the network is.
     */
    public static function config(): array
    {
        return [
            'formats' => self::FORMATS,
            'frequencies' => self::FREQUENCIES,
            'channels' => self::CHANNELS,
            'volumes' => self::VOLUMES,
            'accepts' => array_map(fn ($e) => '.'.$e, self::ACCEPTS),
            'maxInput' => self::MAX_INPUT_BYTES,
            'maxOutput' => self::MAX_OUTPUT_BYTES,
            'maxFiles' => self::MAX_FILES,
            'mobileWarn' => self::MOBILE_WARN_BYTES,
            'core' => self::CORE_PATH,
        ];
    }
}
