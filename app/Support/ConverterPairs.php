<?php

namespace App\Support;

/**
 * The per-pair pages — /convert/mp3-to-wav and its nineteen siblings.
 *
 * WHY THESE EXIST AT ALL. A single generic /converter page does not compete
 * against sites that have held a page per pair for ten years. What ranks is
 * the page whose heading is the words somebody typed into Google, and the
 * only way to have that is to have one page per pair.
 *
 * ─────────────────────────────────────────────────────────────────────
 * THE RISK, WHICH IS REAL AND WORTH NAMING.
 *
 * Twenty pages carrying the same tool and two swapped words is thin
 * content, and Google does not ignore thin content — it penalises it. The
 * pages would cost ranking rather than earn it.
 *
 * So every entry below carries writing that is only true of that pair:
 *
 *   `loses`  — what is thrown away, and whether it comes back. This is the
 *              honest half, and it is also the half nobody else writes.
 *
 *   `gains`  — what the conversion does NOT give you. mp3-to-flac is the
 *              clearest case: a great many people believe it improves the
 *              audio, and it cannot. Saying so on the page they landed on
 *              to do exactly that is the sort of thing a site is
 *              remembered for.
 *
 *   `sizes`  — a real before and after for one minute of audio, so the
 *              decision is a number rather than a feeling.
 *
 *   `when`   — the case where this conversion is the right answer.
 *
 * If there is ever no time to write a pair properly, publish fewer pairs.
 * Five good pages beat twenty empty ones, in ranking and in every other way.
 * ─────────────────────────────────────────────────────────────────────
 */
class ConverterPairs
{
    /**
     * A minute of audio, in megabytes, for the size line on each page.
     *
     * Approximate on purpose and labelled as such: the true number moves
     * with the source material, and a page that prints "2.4 MB" to two
     * decimals is claiming a precision it does not have.
     */
    public const PER_MINUTE = [
        'mp3' => '~1.4 MB',      // 192 kbps
        'wav' => '~10 MB',       // 44.1 kHz · 16-bit · stereo
        'ogg' => '~1.4 MB',      // 192 kbps
        'flac' => '~5–6 MB',
        'm4a' => '~1.4 MB',      // 192 kbps
        'opus' => '~0.9 MB',     // 128 kbps
        'aac' => '~1.4 MB',
        'aiff' => '~10 MB',
        'wma' => '~1.4 MB',
    ];

    /**
     * The pairs, and the words that make each page worth having.
     *
     * Chosen by what people actually search, not by permutation: six output
     * formats would give thirty combinations, and most of them nobody has
     * ever typed. Converting INTO mp3 is the largest group by a wide
     * margin, so it gets the most pages.
     *
     * @var array<string, array<string, string>>
     */
    public const PAIRS = [

        /* ─────────────── Into MP3 — the biggest group ─────────────── */

        'wav-to-mp3' => [
            'from' => 'wav', 'to' => 'mp3',
            'title' => 'Convert WAV to MP3',
            'lead' => 'WAV is uncompressed, which is why it is enormous. MP3 is the format everything on earth can open, at roughly a seventh of the size.',
            'loses' => 'This one does throw data away, and it does not come back. At 192 kbps the difference is inaudible on almost any speaker or headphone; at 128 kbps you may hear it on cymbals and applause. What you cannot do is convert back to WAV later and recover the original — that file will be WAV-sized again but not WAV-quality.',
            'gains' => 'Nothing about the recording improves. MP3 is smaller, more compatible and slightly worse, and those are the whole of the trade.',
            'settings' => '192 kbps is the sensible default and 320 kbps is past the point almost anybody can hear. Drop to 128 only for speech — and to mono as well if it was recorded on one microphone, which halves the file again with nothing lost.',
            'when' => 'Sending a recording to somebody, publishing it, or putting it on a phone. Keep the WAV if it is a master you will edit again.',
        ],

        'm4a-to-mp3' => [
            'from' => 'm4a', 'to' => 'mp3',
            'title' => 'Convert M4A to MP3',
            'lead' => 'M4A is what an iPhone voice memo and most Apple audio come out as. MP3 is what everything else expects.',
            'loses' => 'Both are lossy, so this is a second compression on top of a first — the technical term is a generation loss. In practice, at 192 kbps or above, almost nobody hears it on speech and most people hear nothing on music. It is still a real loss, and doing it repeatedly does accumulate.',
            'gains' => 'No quality is recovered. If the M4A was a 64 kbps voice memo, the MP3 will sound exactly as thin — larger file, same audio.',
            'settings' => 'Match or beat the source. A 256 kbps M4A turned into a 128 kbps MP3 loses twice: once when Apple encoded it and again here. If you do not know the source bitrate, 256 is the safe choice.',
            'when' => 'Something refuses to open an M4A: older car stereos, some editing software, a few upload forms. If whatever you are using already opens M4A, converting only costs you a little quality.',
        ],

        'flac-to-mp3' => [
            'from' => 'flac', 'to' => 'mp3',
            'title' => 'Convert FLAC to MP3',
            'lead' => 'FLAC keeps every bit of the original and costs the space to prove it. MP3 gives up some of those bits for a file a fifth of the size.',
            'loses' => 'Everything FLAC was protecting. This is the conversion where the loss is largest in principle, because you are starting from a perfect copy. Keep the FLAC files — convert to MP3 for the phone or the car and leave the originals where they are.',
            'gains' => 'Portability and space, and nothing else. The music will not sound better in any respect.',
            'settings' => '320 kbps if the destination has room, 256 if it does not. This is the case where paying for the higher bitrate is clearly worth it, because the source is perfect and the only loss happening is the one you choose here.',
            'when' => 'Filling a phone, a car stereo or an old player from a lossless library. At 256 or 320 kbps the result is close enough that the difference is a matter for careful listening on good equipment.',
        ],

        'ogg-to-mp3' => [
            'from' => 'ogg', 'to' => 'mp3',
            'title' => 'Convert OGG to MP3',
            'lead' => 'OGG Vorbis is technically the better format and almost nothing supports it. MP3 is the reverse.',
            'loses' => 'A second lossy compression, and OGG at a given bitrate is usually a little better than MP3 at the same one — so match or exceed the source bitrate if you can.',
            'gains' => 'Compatibility, which is the entire reason anybody does this. The audio does not improve.',
            'settings' => 'Step up one level from the source. Vorbis is a little more efficient than MP3, so an OGG at 160 kbps needs roughly 192 kbps of MP3 to sound the same.',
            'when' => 'A game, a Wikipedia clip or an open-source project handed you an OGG and the thing you want to play it on has never heard of the format.',
        ],

        'aac-to-mp3' => [
            'from' => 'aac', 'to' => 'mp3',
            'title' => 'Convert AAC to MP3',
            'lead' => 'AAC is the successor to MP3 and sounds better at the same bitrate. It is also the one some devices still will not touch.',
            'loses' => 'A generation, as with any lossy-to-lossy conversion. Because AAC is the stronger codec, going to MP3 at the same bitrate loses a little more than the number suggests — step up one level if the size allows.',
            'gains' => 'Nothing but reach. You are trading a better codec for a more common one.',
            'settings' => 'Go up a step. AAC squeezes more out of every kilobyte, so matching the number does not match the quality — a 128 kbps AAC wants 192 kbps of MP3 behind it.',
            'when' => 'A player, a car or a piece of software that only speaks MP3. Otherwise there is no reason to leave AAC.',
        ],

        'opus-to-mp3' => [
            'from' => 'opus', 'to' => 'mp3',
            'title' => 'Convert OPUS to MP3',
            'lead' => 'OPUS is the best-sounding codec per kilobyte there is, and it is mostly used where you never see it: voice calls, WhatsApp, YouTube. MP3 is what the rest of the world plays.',
            'loses' => 'A generation. OPUS at 64 kbps can sound like MP3 at 128, so converting a small OPUS to a small MP3 loses more than it looks — go up in bitrate, and accept the larger file.',
            'gains' => 'Nothing. A voice note recorded at 24 kbps will sound exactly as compressed as an MP3 five times its size.',
            'settings' => 'Go up two steps. OPUS at 64 kbps is roughly MP3 at 128, and a voice note recorded at 24 kbps will never sound better than it does now, however high you set this.',
            'when' => 'A voice note or a downloaded clip that your editor, your player or your car refuses to open.',
        ],

        'aiff-to-mp3' => [
            'from' => 'aiff', 'to' => 'mp3',
            'title' => 'Convert AIFF to MP3',
            'lead' => 'AIFF is what WAV is, from the Apple side of the family: uncompressed, exact and very large.',
            'loses' => 'The same as WAV to MP3 — real, permanent, and inaudible to most people above 192 kbps.',
            'gains' => 'Size and compatibility. AIFF opens on far fewer things than its age suggests.',
            'settings' => '192 or 320 kbps, exactly as with WAV. If the AIFF came off a Mac at 48 kHz, leave the sample rate alone — MP3 handles 48 kHz perfectly well and resampling only costs you.',
            'when' => 'Getting audio out of Logic, GarageBand or an older Mac workflow and into something anybody can play.',
        ],

        'wma-to-mp3' => [
            'from' => 'wma', 'to' => 'mp3',
            'title' => 'Convert WMA to MP3',
            'lead' => 'WMA is a Windows Media format that has been quietly dying since about 2010. Most things made this decade will not open one.',
            'loses' => 'A generation of lossy compression. WMA files are often old and were often encoded at low bitrates, so the source may be the limiting factor rather than the conversion.',
            'gains' => 'Nothing beyond being playable again. An old 96 kbps WMA becomes an equally thin MP3.',
            'settings' => '192 kbps is generous for almost any WMA. These files are usually old and were often encoded at 96 or 128, so a higher setting makes the file bigger without recovering anything at all.',
            'when' => 'Rescuing an old library. This is a conversion people do once, to a whole folder, and never think about again.',
        ],

        /* ─────────────── Out of MP3 ─────────────── */

        'mp3-to-wav' => [
            'from' => 'mp3', 'to' => 'wav',
            'title' => 'Convert MP3 to WAV',
            'lead' => 'WAV is uncompressed audio: no codec, no decoding, just samples. Editors, samplers and older hardware often want exactly that.',
            'loses' => 'Nothing further — WAV keeps whatever it is handed. But this is worth being clear about: the WAV will contain the compressed audio, decompressed. Everything the MP3 threw away stays thrown away.',
            'gains' => 'NO QUALITY IS RECOVERED, and this is the most common misunderstanding on the whole site. The file becomes roughly seven times larger and sounds precisely the same. What you gain is a format that edits without re-encoding and plays on things that cannot decode MP3.',
            'settings' => 'Leave the sample rate alone unless something downstream demands 44.1 or 48 kHz. 16-bit is right for anything that came from an MP3 — 24-bit doubles the size and stores nothing extra, because there is no extra detail in there to store.',
            'when' => 'Loading audio into an editor or a sampler, burning an audio CD, or feeding hardware that only reads PCM.',
        ],

        'mp3-to-ogg' => [
            'from' => 'mp3', 'to' => 'ogg',
            'title' => 'Convert MP3 to OGG',
            'lead' => 'OGG Vorbis is free of patents and generally sounds better than MP3 at the same bitrate — which is why game engines and open-source projects prefer it.',
            'loses' => 'A generation. Re-compressing already-compressed audio always costs something, however good the second codec is.',
            'gains' => 'A slightly smaller file at comparable quality, and a format with no licensing questions attached. Not better audio than the MP3 you started with.',
            'settings' => 'Match the source bitrate or go one step below it. Vorbis is efficient enough that a 192 kbps MP3 converts happily into 160 kbps of OGG with no audible change.',
            'when' => 'Godot, Unity, a web project, or anywhere the licence of the codec is part of the decision.',
        ],

        'mp3-to-flac' => [
            'from' => 'mp3', 'to' => 'flac',
            'title' => 'Convert MP3 to FLAC',
            'lead' => 'FLAC is lossless compression: it stores audio exactly, at about half the size of WAV.',
            'loses' => 'Nothing — FLAC is lossless and will store what it is handed without altering a bit of it. But that is not the point worth making on this page.',
            'gains' => 'NOTHING. This conversion cannot improve the audio and a great many people believe it can. FLAC stores perfectly whatever it is given, and what it is being given here is already-compressed audio with parts permanently removed. You end up with a file three or four times larger that sounds identical to the MP3. If you want a lossless copy of something, you have to go back to a lossless source — there is no other way, and no converter anywhere can do it.',
            'settings' => 'There is nothing to set, and that is itself the point: FLAC has no quality dial because it stores exactly what it is given. The file will come out three or four times larger and sound identical.',
            'when' => 'Almost never, honestly. The one real case is a library or a piece of software that only accepts lossless formats and refuses MP3 outright.',
        ],

        'mp3-to-m4a' => [
            'from' => 'mp3', 'to' => 'm4a',
            'title' => 'Convert MP3 to M4A',
            'lead' => 'M4A wraps AAC audio, which is what Apple devices and most video editors expect to be handed.',
            'loses' => 'A generation. AAC is the better codec, but re-encoding an MP3 through it still costs a little.',
            'gains' => 'Compatibility with Apple software and video timelines. The audio is not improved by the better codec — it can only preserve what the MP3 already had.',
            'settings' => 'Match the source. AAC is the better codec but it cannot invent detail, so pushing a 128 kbps MP3 into a 256 kbps M4A buys nothing but file size.',
            'when' => 'Bringing audio into Final Cut, iMovie or an iOS app that will not take an MP3.',
        ],

        'mp3-to-opus' => [
            'from' => 'mp3', 'to' => 'opus',
            'title' => 'Convert MP3 to OPUS',
            'lead' => 'OPUS gets more out of every kilobyte than anything else in common use, especially on speech.',
            'loses' => 'A generation, as always. But OPUS is efficient enough that even at half the bitrate the result is usually indistinguishable from the MP3 it came from.',
            'gains' => 'A much smaller file for the same perceived quality. Speech in particular can drop to a third of the size with no audible difference.',
            'settings' => 'Halve it. A 192 kbps MP3 becomes a 96 kbps OPUS with no audible difference on most material, and 64 kbps is plenty for anything that is mostly speech.',
            'when' => 'Web delivery, podcasts, voice, or anywhere bandwidth costs money. Not for sending to somebody whose software you do not know — support is still patchy.',
        ],

        /* ─────────────── Between lossless, and back ─────────────── */

        'wav-to-flac' => [
            'from' => 'wav', 'to' => 'flac',
            'title' => 'Convert WAV to FLAC',
            'lead' => 'The one conversion on this site that costs nothing at all. FLAC stores exactly the same audio in roughly half the space.',
            'loses' => 'Nothing. Not "almost nothing" — nothing. Convert back to WAV and you get a bit-for-bit identical file.',
            'gains' => 'About half the disk space, and a format that carries proper tags, which WAV handles badly. Audio quality is unchanged, because it is the same audio.',
            'settings' => 'Nothing to choose, and nothing to get wrong. Keep the sample rate and the channels exactly as they are — changing either is the one way to make a lossless conversion lossy.',
            'when' => 'Archiving masters, recordings or a sample library. If you are storing WAV files long-term, this is close to free money.',
        ],

        'flac-to-wav' => [
            'from' => 'flac', 'to' => 'wav',
            'title' => 'Convert FLAC to WAV',
            'lead' => 'Unpacking lossless compression back into plain samples, for software that will not read FLAC.',
            'loses' => 'Nothing. The audio is identical to what went into the FLAC in the first place.',
            'gains' => 'Nothing except compatibility, at roughly double the size. The audio was already perfect and stays perfect.',
            'settings' => 'Leave everything alone. The FLAC already holds a specific sample rate and bit depth, and resampling on the way out is the only way to lose something in a conversion that should lose nothing.',
            'when' => 'An editor, a sampler or a piece of hardware that only reads WAV. There is no quality reason to do it.',
        ],

        'wav-to-ogg' => [
            'from' => 'wav', 'to' => 'ogg',
            'title' => 'Convert WAV to OGG',
            'lead' => 'From uncompressed to a compact, patent-free format — the usual path for game audio.',
            'loses' => 'Real compression, for the first time on this file. At 192 kbps it is inaudible in a game mix; below 128 kbps you will start to hear it on anything bright.',
            'gains' => 'A file around a seventh of the size, with no codec licensing to think about.',
            'settings' => '192 kbps for music, 128 for speech, 96 if it is going into a game with hundreds of files where every kilobyte is multiplied. Mono halves it again on anything recorded with one microphone.',
            'when' => 'Shipping sound effects or music in a game engine, or on a website where every kilobyte is bandwidth.',
        ],

        'wav-to-m4a' => [
            'from' => 'wav', 'to' => 'm4a',
            'title' => 'Convert WAV to M4A',
            'lead' => 'From an uncompressed master to the compressed format Apple software and video editors prefer.',
            'loses' => 'A first compression, permanent. AAC handles it better than MP3 does at the same bitrate.',
            'gains' => 'A much smaller file that drops straight into a video timeline. Keep the WAV if it is a master.',
            'settings' => '192 kbps for general use, 256 if it is going into a video that will be re-encoded later — the extra headroom survives a second compression noticeably better.',
            'when' => 'Delivering audio for video, or for an Apple workflow, from a recording you made yourself.',
        ],

        'm4a-to-wav' => [
            'from' => 'm4a', 'to' => 'wav',
            'title' => 'Convert M4A to WAV',
            'lead' => 'Turning an Apple voice memo or an AAC track into plain samples an editor can work with.',
            'loses' => 'Nothing further, and nothing is regained either. The AAC inside the M4A has already discarded what it was going to discard; unpacking it into plain samples changes none of that.',
            'gains' => 'No quality. The file grows several times over and contains exactly the audio the M4A already had. What it gains is a format that edits cleanly without another round of compression.',
            'settings' => '16-bit is right. Voice memos are usually mono at 44.1 kHz, and leaving the channels and the rate untouched keeps the file as small as an uncompressed file can be.',
            'when' => 'Editing a voice memo, or feeding software that will not open M4A. Very common for transcription and podcast work.',
        ],

        'aiff-to-wav' => [
            'from' => 'aiff', 'to' => 'wav',
            'title' => 'Convert AIFF to WAV',
            'lead' => 'Two uncompressed formats that store the same thing with a different wrapper — Apple\'s and Microsoft\'s.',
            'loses' => 'Nothing. AIFF and WAV store identical sample data and differ only in the header that describes it, so this is closer to renaming than to converting.',
            'gains' => 'Nothing but compatibility. WAV is read by more software; the audio is identical and so is the size.',
            'settings' => 'Nothing at all. Both formats store the same samples, so any change to the rate, the depth or the channels is a loss introduced by choice rather than by the conversion itself.',
            'when' => 'Moving audio out of a Mac workflow into software that expects WAV.',
        ],

        'ogg-to-wav' => [
            'from' => 'ogg', 'to' => 'wav',
            'title' => 'Convert OGG to WAV',
            'lead' => 'Decoding Vorbis back to plain samples, usually so an editor or an engine can take it.',
            'loses' => 'Nothing further. Vorbis has already done its compression and this step only unpacks the result — the WAV is an exact copy of what the OGG decodes to, on any machine, every time.',
            'gains' => 'No quality — whatever the OGG compression removed is gone, and the WAV simply stores the result at seven times the size. The gain is a format that edits without another compression.',
            'settings' => 'Leave the sample rate and the channels as they are. The OGG has a native rate, and resampling it on the way out costs quality in a step that would otherwise cost none.',
            'when' => 'Bringing game audio into an editor, or feeding hardware that only reads PCM.',
        ],
    ];

    /** Is this a pair we publish a page for? */
    public static function exists(string $slug): bool
    {
        return array_key_exists($slug, self::PAIRS);
    }

    public static function find(string $slug): ?array
    {
        if (! self::exists($slug)) {
            return null;
        }

        return [...self::PAIRS[$slug], 'slug' => $slug];
    }

    /**
     * The other pairs worth offering from a given page.
     *
     * Related, not random: the reverse of what you are looking at, then
     * anything sharing a format with it. Internal links between pages that
     * genuinely belong together is most of what makes a set of pages read
     * as a section rather than twenty strangers.
     *
     * @return array<int, array<string, string>>
     */
    public static function related(string $slug, int $limit = 6): array
    {
        $pair = self::find($slug);

        if (! $pair) {
            return [];
        }

        $reverse = "{$pair['to']}-to-{$pair['from']}";
        $out = [];

        if (self::exists($reverse)) {
            $out[] = self::find($reverse);
        }

        foreach (self::PAIRS as $key => $other) {
            if ($key === $slug || $key === $reverse) {
                continue;
            }

            if ($other['from'] === $pair['from'] || $other['to'] === $pair['to']) {
                $out[] = [...$other, 'slug' => $key];
            }

            if (count($out) >= $limit) {
                break;
            }
        }

        return array_slice($out, 0, $limit);
    }

    /** Every slug, for the sitemap and the index listing. */
    public static function slugs(): array
    {
        return array_keys(self::PAIRS);
    }

    public static function perMinute(string $format): string
    {
        return self::PER_MINUTE[$format] ?? '—';
    }
}
