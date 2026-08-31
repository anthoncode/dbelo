<?php

namespace App\Services;

use App\Models\Search;
use App\Models\SearchDaily;
use Illuminate\Support\Facades\DB;

/**
 * Records what visitors look for.
 *
 * The hard part is not writing rows, it is writing the RIGHT rows. The
 * catalogue searches live, on a 250 ms debounce, so a naive logger stores
 * "pue", "puer", "puert", "puerta" — four rows for one search, and the
 * ranking that should tell you what to record ends up dominated by
 * prefixes of itself.
 *
 * Two defences, deliberately overlapping:
 *
 *   1. The browser only calls this after 1.5 s without typing. By then the
 *      word is finished. That catches almost everything.
 *   2. This class still checks the previous term from the same session: if
 *      it is a prefix of the new one and was logged moments ago, the count
 *      is MOVED rather than added, and a term left at zero is deleted.
 *      That catches the slow typist the debounce lets through.
 */
class SearchLogger
{
    /** How long a term stays "in progress" and can still be superseded. */
    protected const SETTLE_SECONDS = 90;

    protected const MIN_LENGTH = 2;

    /** How long after a search a click still belongs to it. */
    protected const ATTRIBUTION_MINUTES = 15;

    public function record(string $raw, int $results): ?Search
    {
        $term = Search::normalise($raw);

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return null;
        }

        $previous = session('search.pending');

        // Same term again in the same burst: nothing new happened.
        if ($previous && $previous['term'] === $term && $this->isFresh($previous)) {
            $search = Search::find($previous['id']);
            $search?->update(['results' => $results, 'last_seen_at' => now()]);

            return $search;
        }

        $search = DB::transaction(function () use ($term, $raw, $results, $previous) {
            $search = Search::firstOrNew(['term' => $term]);

            $search->fill([
                'raw' => $search->exists ? $search->raw : trim($raw),
                'results' => $results,
                'count' => ($search->count ?? 0) + 1,
                'first_seen_at' => $search->first_seen_at ?? now(),
                'last_seen_at' => now(),
            ])->save();

            $this->bumpDay($search);

            // The typist reached "puerta" after we already stored "puert":
            // take the count back off the prefix instead of leaving a ghost.
            if ($previous && $this->isFresh($previous) && $this->isPrefixOf($previous['term'], $term)) {
                $this->rollBack($previous['id']);
            }

            return $search;
        });

        session([
            'search.pending' => ['id' => $search->id, 'term' => $term, 'at' => now()->timestamp],
            'search.last' => ['id' => $search->id, 'at' => now()->timestamp],
        ]);

        return $search;
    }

    /** Someone opened a sound after searching: the search did its job. */
    public function attributeClick(): void
    {
        if ($id = $this->attributableId()) {
            Search::whereKey($id)->increment('clicks');
        }
    }

    public function attributeDownload(): void
    {
        if ($id = $this->attributableId()) {
            Search::whereKey($id)->increment('downloads');
        }
    }

    // ---------------------------------------------------------------

    protected function attributableId(): ?int
    {
        $last = session('search.last');

        if (! $last) {
            return null;
        }

        return now()->timestamp - $last['at'] <= self::ATTRIBUTION_MINUTES * 60
            ? $last['id']
            : null;
    }

    protected function isFresh(array $previous): bool
    {
        return now()->timestamp - $previous['at'] <= self::SETTLE_SECONDS;
    }

    protected function isPrefixOf(string $shorter, string $longer): bool
    {
        return $shorter !== $longer && str_starts_with($longer, $shorter);
    }

    protected function bumpDay(Search $search): void
    {
        $day = now()->toDateString();

        $row = SearchDaily::firstOrNew(['search_id' => $search->id, 'day' => $day]);
        $row->count = ($row->count ?? 0) + 1;
        $row->save();
    }

    /**
     * Undo one count from a superseded prefix, and remove the row entirely
     * if that was the only time it was ever seen.
     */
    protected function rollBack(int $id): void
    {
        $search = Search::find($id);

        if (! $search) {
            return;
        }

        if ($search->count <= 1) {
            $search->delete();   // cascades the daily rows

            return;
        }

        $search->decrement('count');

        SearchDaily::where('search_id', $id)
            ->where('day', now()->toDateString())
            ->where('count', '>', 0)
            ->decrement('count');
    }
}
