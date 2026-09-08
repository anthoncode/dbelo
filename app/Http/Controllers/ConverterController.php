<?php

namespace App\Http\Controllers;

use App\Support\ConverterPairs;
use Illuminate\View\View;

/**
 * The free audio converter, and the twenty per-pair pages behind it.
 *
 * THE SMALLEST CONTROLLER IN THE APPLICATION, and that is the point. It
 * prints pages. There is no upload route, no temporary disk, no queue, no
 * cleanup job and no rate limit — because the conversion happens in the
 * visitor's browser and this server never touches their file.
 *
 * Which is also why the module cannot take the sound library down with it:
 * a converter that goes viral costs exactly as much as one nobody visits.
 */
class ConverterController extends Controller
{
    /** The tool, with every format on offer. */
    public function index(): View
    {
        /*
         * Shared rather than passed: partials/head is included by the
         * layout component, which has its own data scope.
         *
         * The description is written for the search result, not for the
         * page — it is the sentence that has to win the click against two
         * hundred converters that all upload your file somewhere.
         */
        view()->share('seo', [
            'title' => 'Free audio converter — MP3, WAV, OGG, FLAC, M4A, OPUS',
            'description' => 'Convert audio in your browser. Your file is never uploaded, there is no account, '
                .'and nothing is stored. MP3, WAV, OGG, FLAC, M4A and OPUS.',
        ]);

        return view('converter');
    }

    /**
     * One pair — /convert/wav-to-mp3 and its nineteen siblings.
     *
     * A 404 for anything not in the list, deliberately. Answering
     * /convert/anything-to-whatever with a generated page is how a site
     * accumulates thousands of near-identical URLs that Google reads as
     * spam, and the list is short enough to be a whitelist.
     */
    public function pair(string $pair): View
    {
        abort_unless(ConverterPairs::exists($pair), 404);

        $data = ConverterPairs::find($pair);

        $from = strtoupper($data['from']);
        $to = strtoupper($data['to']);

        view()->share('seo', [
            // The heading, the title and the search are the same words on
            // purpose. Anything cleverer competes with itself.
            'title' => "{$from} to {$to} converter — free, in your browser",
            'description' => "Convert {$from} to {$to} without uploading anything. "
                ."{$data['lead']}",
        ]);

        return view('converter-pair', ['pair' => $data]);
    }
}
