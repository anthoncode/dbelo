<?php

namespace App\Support;

/**
 * The vocabulary of the music side of the catalogue.
 *
 * ── WHY CONSTANTS AND NOT TABLES ─────────────────────────────────────────
 *
 * `music_attributes` stores genre and mood as varchar(60), not as foreign
 * keys. That was decided when the table was written, and it is the right
 * call: a genre is a label, not a thing with its own page, its own sort
 * order and its own sounds_count. Categories earn a table because they
 * have all three; "Hip Hop" has none of them.
 *
 * Adding tables now would create a second place where the list lives, with
 * nothing in the database enforcing that a row in `genres` matches the
 * string actually written on a sound. One definition, read by the upload
 * form, the bulk form and the public filters.
 *
 * ── WHY CLOSED LISTS AND NOT FREE TEXT ───────────────────────────────────
 *
 * Tags are free text on purpose — they are search fuel and a near-miss
 * still helps. Genre is the opposite: it is navigation. A filter built on
 * free text accumulates "Hip Hop", "hip-hop" and "HipHop" as three
 * separate options within a week, and then the filter is worse than no
 * filter, because it hides results instead of narrowing them.
 *
 * Everything here is in English, like the rest of the site's content.
 *
 * ── HOW TO ADD ONE ───────────────────────────────────────────────────────
 *
 * Append to the array. Nothing else to change: every screen reads these
 * constants. REMOVING one is the careful operation — sounds already carry
 * the string, so a removed genre keeps showing on their pages while
 * disappearing from the filters. Check first:
 *
 *   MusicAttribute::where('genre', 'Folk')->count()
 */
class Music
{
    /**
     * The two kinds of thing in the catalogue, matching sounds.type.
     *
     * 'sfx' is the default in the migration and in SoundImporter, so a
     * sound that nobody classified is a sound effect — which is what dbelo
     * is, and the safer side to be wrong on: an effect filed as music
     * disappears from the effects catalogue, where a track filed as an
     * effect merely looks out of place.
     */
    public const TYPE_SFX = 'sfx';

    public const TYPE_MUSIC = 'music';

    public const TYPES = [
        self::TYPE_SFX => 'Sound effect',
        self::TYPE_MUSIC => 'Music',
    ];

    /**
     * Genre: what style the track is.
     *
     * This list is deliberately short. Thirty options is a wall of choices
     * at upload time and a wall of links on the public page, and the long
     * tail of any music catalogue is three tracks per entry — which reads
     * as an empty shop. Sub-styles belong in the tags, where they cost
     * nothing and still turn up in search.
     *
     * Ordered by how much stock music actually exists in each, not
     * alphabetically: the form is filled in dozens of times a week and the
     * common answers should be near the top.
     */
    public const GENRES = [
        'Cinematic',
        'Corporate',
        'Electronic',
        'Ambient',
        'Rock',
        'Pop',
        'Hip Hop',
        'Trailer',
        'Film Score',
        'Dance',
        'Acoustic',
        'Folk',
        'Jazz',
        'Classical',
        'Funk',
        'Soul',
        'Latin',
        'World',
        'Country',
        'Metal',
        'Experimental',
    ];

    /**
     * Mood: what the track feels like.
     *
     * A SECOND axis, not a finer version of the first. This is the one
     * people actually search with — an editor cutting a product video does
     * not want "Corporate", they want "Upbeat", and whether that arrives as
     * Corporate or as Electronic is beside the point.
     *
     * Kept to one word each so the public filter is a row of pills rather
     * than a dropdown nobody opens.
     */
    public const MOODS = [
        'Upbeat',
        'Inspirational',
        'Chill',
        'Dramatic',
        'Dark',
        'Action',
        'Aggressive',
        'Peaceful',
        'Fun',
        'Sad',
        'Romantic',
        'Mysterious',
    ];

    /**
     * Musical key, in the notation every stock library uses.
     *
     * Majors first, then minors, each in chromatic order — so the list
     * reads as a keyboard and not as an alphabet. A lowercase 'm' is the
     * minor marker, and sharps are preferred over flats except where the
     * flat spelling is the common one (Eb, Ab, Bb), which is what a
     * musician typing this in expects to find.
     *
     * Why it matters at all: somebody scoring a scene with two tracks needs
     * them in compatible keys, and that is the one question no amount of
     * listening answers quickly. It is also the field most often left
     * empty, which is fine — it is nullable in the table.
     */
    public const KEYS = [
        'C', 'C#', 'D', 'Eb', 'E', 'F', 'F#', 'G', 'Ab', 'A', 'Bb', 'B',
        'Cm', 'C#m', 'Dm', 'Ebm', 'Em', 'Fm', 'F#m', 'Gm', 'G#m', 'Am', 'Bbm', 'Bm',
    ];

    /**
     * The range a tempo can plausibly fall in.
     *
     * Not an arbitrary sanity check: these are the bounds either side of
     * which the number is almost certainly a typo. 60 BPM is a funeral
     * march, 200 is drum and bass at full tilt, and anything outside 20–300
     * is a mistyped duration or a doubled value. Validation uses them so a
     * stray keypress cannot put 1400 BPM on a public page.
     */
    public const BPM_MIN = 20;

    public const BPM_MAX = 300;

    /**
     * The validation rules for the five music fields, in one place.
     *
     * Shared by the single edit screen and the bulk uploader, because a
     * rule written twice is a rule that disagrees the first time one copy
     * is fixed — the same reason Tag::idsFromList exists.
     *
     * Genre and mood validate with `in:` against the constants above, so a
     * crafted request cannot write a sixth genre into a column the filters
     * then have to cope with. Everything is nullable: a track whose tempo
     * nobody measured is still a track worth publishing, and refusing to
     * save it would just mean it never gets uploaded.
     *
     * @param  string  $prefix  Property-name prefix, for a form whose fields
     *                          are not called `genre` but `bulkGenre`.
     * @return array<string, array<int, mixed>>
     */
    public static function rules(string $prefix = ''): array
    {
        $name = fn (string $field) => $prefix === ''
            ? $field
            : $prefix.ucfirst($field);

        return [
            $name('genre') => ['nullable', 'string', 'in:'.implode(',', self::GENRES)],
            $name('mood') => ['nullable', 'string', 'in:'.implode(',', self::MOODS)],
            $name('bpm') => ['nullable', 'integer', 'min:'.self::BPM_MIN, 'max:'.self::BPM_MAX],
            $name('musicalKey') => ['nullable', 'string', 'in:'.implode(',', self::KEYS)],
            $name('hasVocals') => ['boolean'],
        ];
    }

    /**
     * Normalise what a form collected into what the table takes.
     *
     * Empty strings become null, because '' and NULL in a varchar column
     * are two values that mean the same thing and the filters would have to
     * ask about both — the bug we already hit on sounds.description.
     *
     * @return array{genre: ?string, mood: ?string, bpm: ?int, musical_key: ?string, has_vocals: bool}
     */
    public static function attributes(
        string $genre,
        string $mood,
        string $bpm,
        string $musicalKey,
        bool $hasVocals,
    ): array {
        return [
            'genre' => $genre !== '' ? $genre : null,
            'mood' => $mood !== '' ? $mood : null,
            'bpm' => $bpm !== '' ? (int) $bpm : null,
            'musical_key' => $musicalKey !== '' ? $musicalKey : null,
            'has_vocals' => $hasVocals,
        ];
    }

    /**
     * Is there anything here worth keeping a row for?
     *
     * Used before writing: a track whose five fields are all empty gets no
     * row in `music_attributes` at all, rather than a row of nulls. The
     * difference shows up on the public side, where "tracks with a known
     * BPM" is a real query and a row of nulls answers it wrongly.
     */
    public static function isEmpty(array $attributes): bool
    {
        return blank($attributes['genre'])
            && blank($attributes['mood'])
            && blank($attributes['bpm'])
            && blank($attributes['musical_key'])
            && $attributes['has_vocals'] === false;
    }
}
