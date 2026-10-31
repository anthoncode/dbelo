<?php

namespace App\Services;

use App\Models\Collection as Pack;
use App\Models\Download;
use App\Models\Sound;
use App\Models\User;
use App\Support\Downloads;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZipStream\ZipStream;

/**
 * A whole pack, as one zip.
 *
 * ── WHY THIS IS NOT AudioExport WITH A FLAG ──────────────────────────────
 *
 * AudioExport already streams a zip and already filters by collection, and
 * reusing it was the obvious move. It is the wrong one, because the two
 * differ in the part that matters: AudioExport is the operator taking their
 * own masters out of their own system. Nothing in it asks who you are,
 * because the admin area already did.
 *
 * This hands files to the public. Every line below that AudioExport does
 * not have is a line about entitlement — which sounds this person may take,
 * what it costs them, and what licence they just accepted. Bolting that
 * onto a backup tool would put the business model inside something whose
 * job is moving bytes, and the day somebody changed the export filters they
 * would be changing who gets paid.
 *
 * What IS shared is the mechanism, and the comments there explain it: the
 * zip streams, memory stays flat, nothing touches the server's disk. On
 * shared hosting that is not an optimisation, it is the difference between
 * working and not.
 *
 * ── THE ZIP CONTAINS WHAT YOU ARE ENTITLED TO, NOT ALL OR NOTHING ────────
 *
 * A pack has no premium flag of its own. It is a bag of sounds and each one
 * carries its own, which is why Collection keeps premium_count and says on
 * the page: "4 of 12 sounds need a subscription. The rest are free."
 *
 * So the archive is built to match that sentence. A subscriber gets the
 * twelve. Everybody else gets the eight, and a line in LICENSE.txt naming
 * what was left out and why — not a silent short archive, and not a locked
 * door in front of eight files they are entitled to.
 *
 * A pack where nothing is available never reaches this class; the
 * controller turns that into the plans page, where it is an offer rather
 * than an empty download.
 */
class PackArchive
{
    /**
     * The most sounds one archive will carry.
     *
     * A ceiling exists for the same reason AudioExport has one, and it is
     * not about memory: the stream does not care. It is that a browser
     * download has no resume. On shared hosting with a CPU ceiling, a
     * four-hundred-file archive that dies at file three hundred wasted
     * everything and tells the person nothing useful.
     *
     * Fifty is generous for a curated pack and small enough to finish.
     * Past it the controller says so and points at the individual sounds,
     * which is a worse answer than "yes" and a far better one than a
     * truncated file that looks complete.
     */
    public const MAX_SOUNDS = 50;

    /**
     * The sounds in this pack that the requester may actually take.
     *
     * Published only: a pack can outlive the moderation status of what is
     * in it, and an unpublished sound reaching the public through a pack
     * would be a side door around the review queue.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Sound>
     */
    public function available(Pack $pack, ?User $user): \Illuminate\Database\Eloquent\Collection
    {
        $premiumAllowed = (bool) $user?->currentPlan()?->allows_premium;

        return $pack->sounds()
            ->published()
            ->when(! $premiumAllowed, fn ($q) => $q->where('is_premium', false))
            ->with(['files', 'license'])
            ->orderBy('collection_sound.sort_order')
            ->get()
            // A sound whose MP3 never finished converting has nothing to
            // put in the archive. Dropped here rather than skipped during
            // the stream, so the count shown on the button and the count
            // inside the zip are the same number.
            ->filter(fn (Sound $sound) => $sound->downloadFile('mp3') !== null)
            ->values();
    }

    /**
     * How many of the pack's sounds this person cannot have.
     *
     * Reported so LICENSE.txt and the page can say it out loud. Silence
     * here is what turns "you are entitled to eight of twelve" into "the
     * download is broken".
     */
    public function withheld(Pack $pack, ?User $user): int
    {
        $total = $pack->sounds()->published()->count();

        return max(0, $total - $this->available($pack, $user)->count());
    }

    /**
     * Stream the archive, and record what was taken.
     *
     * ── THE ROWS ARE WRITTEN BEFORE THE FIRST BYTE GOES OUT ──────────────
     *
     * Not during the stream, and not after. During means a connection
     * dropped at file nine leaves nine rows and a person with nothing;
     * after means a dropped connection leaves no record of files they do
     * have. Neither is recoverable later, because nothing on this end
     * learns how the download ended.
     *
     * Written first, the error is in one direction only: somebody may be
     * charged for an archive their browser lost. That is the direction the
     * rest of this application already chose — a re-download is free, so
     * they can simply ask again and the second attempt costs nothing.
     */
    public function stream(Pack $pack, ?User $user, Request $request, \Illuminate\Database\Eloquent\Collection $sounds): void
    {
        $this->record($sounds, $user, $request);

        $zip = new ZipStream(
            // streamDownload() has already sent the headers.
            sendHttpHeaders: false,
            // Sizes are not known up front when reading from a stream.
            defaultEnableZeroHeader: true,
        );

        $zip->addFile(
            fileName: 'LICENSE.txt',
            data: $this->licenceText($pack, $sounds, $this->withheld($pack, $user)),
        );

        $used = [];

        foreach ($sounds as $sound) {
            $file = $sound->downloadFile('mp3');

            try {
                $handle = Storage::disk($file->disk)->readStream($file->path);

                if (! $handle) {
                    continue;
                }

                $zip->addFileFromStream(
                    fileName: $this->nameFor($sound, $file->path, $used),
                    stream: $handle,
                );

                if (is_resource($handle)) {
                    fclose($handle);
                }
            } catch (Throwable $e) {
                /*
                 * One unreadable file does not throw away the archive.
                 * Twenty files in, everything already written is worth
                 * having, and a missing master is a job for the Storage
                 * screen's scan — not a reason to hand somebody a broken
                 * download they cannot retry into working.
                 */
                Log::warning('Pack archive skipped a file', [
                    'pack' => $pack->id,
                    'sound' => $sound->id,
                    'path' => $file->path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $zip->finish();
    }

    /**
     * One row per sound, each with its own frozen licence.
     *
     * NOT one row for the pack. "Downloading is accepting", and what gets
     * accepted is a licence per sound — they can differ inside one pack,
     * and the snapshot is what lets somebody prove a year from now which
     * terms they took. A single pack-shaped row would make downloads_count
     * meaningless too, since the number would stop matching the files that
     * left.
     *
     * A sound this person already has is skipped, the same rule the single
     * download follows: a repeat is not a second download.
     */
    protected function record(\Illuminate\Database\Eloquent\Collection $sounds, ?User $user, Request $request): void
    {
        $agent = Str::limit((string) $request->userAgent(), 250, '');
        $ip = $request->ip();
        $now = now();

        foreach ($sounds as $sound) {
            $repeat = $user
                ? $user->downloads()->where('sound_id', $sound->id)->exists()
                : Downloads::guestHasTaken($request, $sound->id);

            if ($repeat) {
                continue;
            }

            $file = $sound->downloadFile('mp3');

            Download::create([
                'user_id' => $user?->id,
                'sound_id' => $sound->id,
                'sound_file_id' => $file?->id,
                'license_id' => $sound->license_id,
                'license_snapshot' => $sound->license?->toSnapshot(),
                'ip_address' => $ip,
                'user_agent' => $agent,
                'created_at' => $now,
            ]);

            $sound->increment('downloads_count');
        }
    }

    /**
     * The text file that rides inside the archive.
     *
     * A pack is the one place where somebody ends up holding forty files
     * with no page in front of them. Six months later the folder on their
     * disk is the only record of what they may do with it, so the terms
     * travel with the audio rather than staying on a URL they did not
     * bookmark.
     *
     * Per sound, not per pack, because the licences can differ — and the
     * withheld count is stated plainly so a short archive reads as a rule
     * rather than a fault.
     */
    protected function licenceText(Pack $pack, \Illuminate\Database\Eloquent\Collection $sounds, int $withheld): string
    {
        $site = (string) config('app.name', 'dbelo');
        $lines = [
            $site.' — '.$pack->name,
            str_repeat('=', mb_strlen($site.' — '.$pack->name)),
            '',
            'Downloaded '.now()->toDayDateTimeString().'.',
            $sounds->count().' '.Str::plural('file', $sounds->count()).' in this archive.',
            '',
        ];

        if ($withheld > 0) {
            $lines[] = $withheld.' '.Str::plural('sound', $withheld).' in this pack '
                .($withheld === 1 ? 'is' : 'are').' not included: '
                .'they need a subscription. Everything here is yours under the terms below.';
            $lines[] = '';
        }

        $lines[] = 'TERMS, PER SOUND';
        $lines[] = str_repeat('-', 24);
        $lines[] = '';

        foreach ($sounds as $sound) {
            $licence = $sound->license;

            $lines[] = $sound->title;
            $lines[] = '  Licence: '.($licence?->name ?? 'No licence recorded');

            if ($licence) {
                $lines[] = '  Attribution required: '.($licence->requires_attribution ? 'yes' : 'no');
                $lines[] = '  Commercial use: '.($licence->allows_commercial ? 'allowed' : 'not allowed');
                $lines[] = '  Modifications: '.($licence->allows_derivatives ? 'allowed' : 'not allowed');

                if ($licence->url) {
                    $lines[] = '  Full text: '.$licence->url;
                }
            }

            $lines[] = '  Page: '.route('sounds.show', $sound);
            $lines[] = '';
        }

        $lines[] = 'The full licence texts are at '.route('legal.licenses').'.';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * What each file is called once the archive is open.
     *
     * Flat, not foldered: AudioExport nests by purpose because it carries
     * masters and previews of the same sound. A pack carries one file per
     * sound, and a folder with one thing in it is a click somebody has to
     * make for nothing.
     *
     * The counter guards duplicates. Two sounds with the same slugged
     * title is unlikely and a duplicate name inside a zip is a file the
     * archive silently loses on extraction, so it is worth the four lines.
     *
     * @param  array<string, int>  $used
     */
    protected function nameFor(Sound $sound, string $path, array &$used): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION) ?: 'mp3';
        $name = Downloads::filename($sound->title, $extension);

        if (isset($used[$name])) {
            $used[$name]++;
            $name = Downloads::filename($sound->title.'-'.$used[$name], $extension);
        } else {
            $used[$name] = 1;
        }

        return $name;
    }

    public static function filename(Pack $pack): string
    {
        $site = Str::slug((string) config('app.name', 'dbelo'));
        $name = Str::slug($pack->name) ?: 'pack';

        return trim($site.'-'.$name, '-').'.zip';
    }
}
