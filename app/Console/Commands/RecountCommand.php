<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Put the denormalised counters back in step with the rows they count.
 *
 * ── WHY THESE COLUMNS DRIFT AT ALL ───────────────────────────────────────
 *
 * downloads_count and favorites_count exist so the catalogue can sort by
 * popularity without counting a million rows on every page load. The price
 * of that is a number kept in step by hand: every path that adds or removes
 * a row has to remember to move it, and any path that does not — a manual
 * DELETE in a client, a foreign-key cascade, a restored backup, a bug —
 * leaves the column lying. Nothing throws. The only symptom is a catalogue
 * ordered by a figure that is quietly wrong.
 *
 * ── WHY THIS IS SAFE, WHERE DELETING ORPHANS IS NOT ──────────────────────
 *
 * It writes only numbers, and only numbers it just derived from the rows
 * themselves. Run it twice and the second run changes nothing; run it after
 * a mistake and it corrects itself. Nothing it does can be regretted, which
 * is exactly what is not true of anything that removes a row — and is why
 * Diagnostics reports orphans and offers no command to clear them.
 *
 * plays_count is deliberately absent. Individual plays are not recorded
 * anywhere, so there is no truth to recompute it from: it is a tally, not a
 * cache, and overwriting it would destroy the only copy.
 */
class RecountCommand extends Command
{
    protected $signature = 'sounds:recount
        {--pretend : Print what would change and write nothing.}';

    protected $description = 'Recompute the download and favourite counters on sounds';

    public function handle(): int
    {
        if (! Schema::hasTable('sounds')) {
            $this->error('No sounds table.');

            return self::FAILURE;
        }

        $this->newLine();

        $fixed = 0;

        foreach ($this->columns() as $column => $table) {
            if (! Schema::hasTable($table)) {
                $this->line("  <fg=gray>skipped</>  {$column} — no {$table} table");

                continue;
            }

            /*
             * The wrong rows are found with a correlated subquery rather than
             * loaded and compared in PHP. On a catalogue of any size the
             * difference is one query against one query per sound, and this
             * is a command somebody runs when they already think something is
             * wrong — the moment to be least expensive, not most.
             */
            $wrong = DB::table('sounds')
                ->whereNull('deleted_at')
                ->whereRaw("{$column} <> (select count(*) from {$table} where {$table}.sound_id = sounds.id)")
                ->select('id', 'title', $column)
                ->orderBy('id')
                ->get();

            if ($wrong->isEmpty()) {
                $this->line("  <fg=green>ok</>       {$column} — every sound matches");

                continue;
            }

            $this->line("  <fg=yellow>drift</>    {$column} — ".$wrong->count().' sounds');

            foreach ($wrong as $sound) {
                $real = DB::table($table)->where('sound_id', $sound->id)->count();

                $this->line(sprintf(
                    '           %-6s %-44s %s → %s',
                    $sound->id,
                    mb_strimwidth($sound->title, 0, 44, '…'),
                    $sound->{$column},
                    $real,
                ));

                if (! $this->option('pretend')) {
                    // updateQuietly in spirit: the query builder fires no
                    // model events, so this cannot trip Scout into reindexing
                    // the whole catalogue one row at a time.
                    DB::table('sounds')->where('id', $sound->id)->update([$column => $real]);
                    $fixed++;
                }
            }
        }

        $this->newLine();

        if ($this->option('pretend')) {
            $this->info('Nothing was written. Run again without --pretend to apply.');

            return self::SUCCESS;
        }

        $this->info($fixed === 0 ? 'Nothing needed changing.' : "{$fixed} counters corrected.");

        return self::SUCCESS;
    }

    /** @return array<string, string> column on sounds => table it counts */
    private function columns(): array
    {
        return [
            'downloads_count' => 'downloads',
            'favorites_count' => 'favorites',
        ];
    }
}
