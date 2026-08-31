<?php

namespace App\Http\Controllers;

use App\Models\Download;
use App\Models\Sound;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only way a real audio file leaves the server.
 *
 * Files live on a private disk, so this controller is the single gate where
 * the plan, the daily quota and the license are enforced.
 */
class DownloadController extends Controller
{
    public function __invoke(Request $request, Sound $sound): StreamedResponse
    {
        // 451 Unavailable For Legal Reasons: the file exists and the person
        // is allowed to ask for it — we are the ones who cannot hand it over
        // while a copyright claim is open.
        if ($sound->isUnderClaim()) {
            abort(451, 'This sound is temporarily unavailable while a copyright claim is reviewed.');
        }

        abort_unless($sound->isPublished(), 404);

        $user = $request->user();

        abort_unless($user, 403, 'You need an account to download sounds.');

        if ($sound->is_premium && ! $user->currentPlan()?->allows_premium) {
            abort(403, 'This sound is available with a Pro subscription.');
        }

        if (! $user->canDownload($sound)) {
            abort(429, 'You have reached your daily download limit. Upgrade for unlimited downloads.');
        }

        $file = $sound->downloadFile('mp3');

        abort_unless($file, 404, 'No downloadable file for this sound.');

        Download::create([
            'user_id' => $user->id,
            'sound_id' => $sound->id,
            'sound_file_id' => $file->id,
            'license_id' => $sound->license_id,
            // Freeze the terms as they stand today. If the license changes
            // next year, this user still holds what they accepted.
            'license_snapshot' => $sound->license?->toSnapshot(),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            'created_at' => now(),
        ]);

        $sound->increment('downloads_count');

        // If a search led here, credit it. This is what turns the search log
        // from a list of words into a measure of whether search works.
        app(\App\Services\SearchLogger::class)->attributeDownload();

        return Storage::disk($file->disk)->download(
            $file->path,
            Str::slug($sound->title).'.mp3'
        );
    }
}
