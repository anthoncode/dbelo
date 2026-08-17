<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Thin wrapper around ffmpeg / ffprobe.
 *
 * Everything audio-related that touches the filesystem lives here, so the
 * job stays readable and this class can be tested on its own.
 */
class AudioProcessor
{
    public function __construct(
        protected string $ffmpeg = 'ffmpeg',
        protected string $ffprobe = 'ffprobe',
    ) {}

    /**
     * Read technical metadata from an audio file.
     *
     * @return array{duration_ms:int, sample_rate:int|null, bit_depth:int|null, channels:int, format:string|null}
     */
    public function probe(string $absolutePath): array
    {
        $result = Process::run([
            $this->ffprobe,
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $absolutePath,
        ]);

        if (! $result->successful()) {
            throw new RuntimeException("ffprobe failed on {$absolutePath}: ".$result->errorOutput());
        }

        $data = json_decode($result->output(), true);

        $stream = collect($data['streams'] ?? [])
            ->firstWhere('codec_type', 'audio');

        if (! $stream) {
            throw new RuntimeException("No audio stream found in {$absolutePath}");
        }

        $seconds = (float) ($data['format']['duration'] ?? $stream['duration'] ?? 0);

        return [
            'duration_ms' => (int) round($seconds * 1000),
            'sample_rate' => isset($stream['sample_rate']) ? (int) $stream['sample_rate'] : null,
            'bit_depth' => $this->bitDepth($stream),
            'channels' => (int) ($stream['channels'] ?? 2),
            'format' => $data['format']['format_name'] ?? null,
        ];
    }

    /**
     * Low quality MP3 for the public player. Small and fast to stream.
     */
    public function makePreview(string $source, string $destination): void
    {
        $this->transcode($source, $destination, bitrate: '128k');
    }

    /**
     * Higher quality MP3 offered as the free download.
     */
    public function makeDownload(string $source, string $destination): void
    {
        $this->transcode($source, $destination, bitrate: '320k');
    }

    protected function transcode(string $source, string $destination, string $bitrate): void
    {
        @mkdir(dirname($destination), 0755, true);

        $result = Process::timeout(300)->run([
            $this->ffmpeg,
            '-y',                     // overwrite silently
            '-i', $source,
            '-codec:a', 'libmp3lame',
            '-b:a', $bitrate,
            '-ar', '44100',
            $destination,
        ]);

        if (! $result->successful()) {
            throw new RuntimeException("ffmpeg failed converting {$source}: ".$result->errorOutput());
        }
    }

    /**
     * Precomputed waveform peaks, normalised to 0..1.
     *
     * Doing this once at upload time is what keeps the player instant: the
     * browser draws a small array of numbers instead of downloading and
     * analysing the audio on every page view.
     *
     * @return array<int, float>
     */
    public function waveform(string $absolutePath, int $buckets = 400): array
    {
        // Decode to raw mono 16-bit PCM at a low sample rate: enough detail
        // to draw with, tiny enough to hold in memory.
        $result = Process::timeout(300)->run([
            $this->ffmpeg,
            '-v', 'quiet',
            '-i', $absolutePath,
            '-ac', '1',
            '-filter:a', 'aresample=8000',
            '-map', '0:a',
            '-c:a', 'pcm_s16le',
            '-f', 'data',
            '-',
        ]);

        if (! $result->successful()) {
            throw new RuntimeException("ffmpeg failed reading peaks from {$absolutePath}");
        }

        $raw = $result->output();
        $samples = unpack('s*', $raw) ?: [];
        $total = count($samples);

        if ($total === 0) {
            return [];
        }

        $samples = array_values($samples);
        $perBucket = max(1, (int) floor($total / $buckets));
        $peaks = [];

        for ($i = 0; $i < $buckets; $i++) {
            $slice = array_slice($samples, $i * $perBucket, $perBucket);

            if ($slice === []) {
                break;
            }

            $peak = 0;

            foreach ($slice as $sample) {
                $abs = abs($sample);

                if ($abs > $peak) {
                    $peak = $abs;
                }
            }

            $peaks[] = round($peak / 32768, 4);
        }

        return $peaks;
    }

    public function isAvailable(): bool
    {
        return Process::run([$this->ffmpeg, '-version'])->successful();
    }

    protected function bitDepth(array $stream): ?int
    {
        if (isset($stream['bits_per_raw_sample']) && $stream['bits_per_raw_sample'] !== 'N/A') {
            return (int) $stream['bits_per_raw_sample'];
        }

        if (isset($stream['bits_per_sample']) && (int) $stream['bits_per_sample'] > 0) {
            return (int) $stream['bits_per_sample'];
        }

        return null;
    }
}
