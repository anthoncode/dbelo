<?php

namespace App\Support;

/**
 * A line-by-line diff, so a proposed rewrite can be read rather than trusted.
 *
 * ── WHY THIS EXISTS AND NOT A PACKAGE ────────────────────────────────────
 *
 * It is thirty lines of longest-common-subsequence and it runs on one
 * article at a time in an admin screen. A dependency for that is a
 * dependency to keep updated, audit and explain, in exchange for code
 * shorter than its own composer entry.
 *
 * ── WHY LINES AND NOT WORDS ──────────────────────────────────────────────
 *
 * A word-level diff of a rewritten paragraph is a red-and-green confetti
 * that takes longer to read than the paragraph. Markdown is already written
 * in meaningful lines — a heading, a paragraph, a list item — so the line is
 * the unit a writer already thinks in, and "this paragraph changed" is the
 * answer they actually want.
 */
class LineDiff
{
    /**
     * Above this, the table stops being worth building.
     *
     * The algorithm is O(n × m): two thousand lines against two thousand is
     * four million cells, which is slow and memory-hungry for a screen
     * somebody is waiting on. Long documents fall back to "everything
     * changed", which is honest — and at that length the side-by-side is
     * what gets read anyway.
     */
    private const MAX_LINES = 900;

    /**
     * @return array<int, array{type: string, text: string}>  type: same | add | del
     */
    public static function between(string $before, string $after): array
    {
        $a = preg_split("/\r\n|\r|\n/", $before);
        $b = preg_split("/\r\n|\r|\n/", $after);

        if (count($a) > self::MAX_LINES || count($b) > self::MAX_LINES) {
            return array_merge(
                array_map(fn ($line) => ['type' => 'del', 'text' => $line], $a),
                array_map(fn ($line) => ['type' => 'add', 'text' => $line], $b),
            );
        }

        return self::walk($a, $b, self::table($a, $b));
    }

    /** How many changed lines there are, for a one-line summary. */
    public static function countChanges(array $ops): int
    {
        return count(array_filter($ops, fn ($op) => $op['type'] !== 'same'));
    }

    /**
     * Lengths of the longest common subsequence for every pair of suffixes.
     *
     * Lines are compared TRIMMED. Trailing whitespace is invisible on screen,
     * and a diff that highlights a paragraph because a space was removed from
     * the end of it is a diff nobody reads twice.
     */
    private static function table(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = trim($a[$i]) === trim($b[$j])
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        return $lcs;
    }

    private static function walk(array $a, array $b, array $lcs): array
    {
        $ops = [];
        $i = 0;
        $j = 0;
        $n = count($a);
        $m = count($b);

        while ($i < $n && $j < $m) {
            if (trim($a[$i]) === trim($b[$j])) {
                // The AFTER version is kept, not the before: when only the
                // whitespace differs the proposal is what will be applied.
                $ops[] = ['type' => 'same', 'text' => $b[$j]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $ops[] = ['type' => 'del', 'text' => $a[$i]];
                $i++;
            } else {
                $ops[] = ['type' => 'add', 'text' => $b[$j]];
                $j++;
            }
        }

        while ($i < $n) {
            $ops[] = ['type' => 'del', 'text' => $a[$i]];
            $i++;
        }

        while ($j < $m) {
            $ops[] = ['type' => 'add', 'text' => $b[$j]];
            $j++;
        }

        return $ops;
    }
}
