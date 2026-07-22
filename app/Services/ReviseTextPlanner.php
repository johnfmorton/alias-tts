<?php

namespace App\Services;

/**
 * Pure diff planner for "Revise text": aligns a project's existing chunks with
 * the candidate segments produced by re-running the ingest pipeline over a
 * revised manuscript, and emits the minimal change plan that keeps every chunk
 * row whose spoken text survives — audio, takes, tuning, per-chunk voice, seed
 * pins, skip flags and spend all ride on row identity, so "keep the row" is
 * what makes a revision cheap.
 *
 * The alignment is an LCS diff at chunk granularity (after trimming the
 * common prefix/suffix, which reduces a typical small revision to a handful of
 * cells). Within each changed run, old and new entries pair positionally into
 * in-place UPDATES — the revision analogue of editing a chunk card, keeping
 * the row's history through a one-word change. Leftover deletions and
 * insertions are then rescued by exact-text matching in document order, so a
 * paragraph that merely MOVED carries its audio to the new position instead of
 * re-rendering.
 *
 * Pure: arrays in, arrays out, no models, no I/O — see ReviseTextPlannerTest.
 */
class ReviseTextPlanner
{
    /**
     * Middles bigger than this many DP cells fall back to pairing the whole
     * changed span positionally — still correct, just a coarser diff. With
     * prefix/suffix trimming, real revisions never get near it.
     */
    private const MAX_LCS_CELLS = 1_000_000;

    /**
     * @param  list<array{id: string, text: string, break_after: string, has_audio: bool}>  $old  chunks in position order
     * @param  list<array{text: string, breakAfter: string}>  $new  pipeline segments in document order
     * @return array{
     *     ops: list<array<string, mixed>>,
     *     deletes: list<array{id: string, text: string}>,
     *     counts: array{keep: int, moved: int, break_only: int, update: int, insert: int, delete: int},
     *     changed: bool,
     * } ops in final document order (each op carries its new 'position'); deletes listed separately
     */
    public function plan(array $old, array $new): array
    {
        $script = $this->diffScript(array_values($old), array_values($new));
        [$ops, $deletes] = $this->pairRuns($script);
        [$ops, $deletes] = $this->rescueMoves($ops, $deletes);

        $counts = ['keep' => 0, 'moved' => 0, 'break_only' => 0, 'update' => 0, 'insert' => 0, 'delete' => count($deletes)];
        foreach ($ops as $i => $op) {
            $counts[$op['op']]++;
            if ($op['op'] === 'keep') {
                $counts['moved'] += $op['moved'] ? 1 : 0;
                $counts['break_only'] += (! $op['moved'] && $op['break_changed']) ? 1 : 0;
            }
            $ops[$i]['position'] = $i;
        }

        // A keep whose row neither moved nor changed its break is a true no-op;
        // anything else means the document changed and the final is outdated.
        $changed = $counts['update'] + $counts['insert'] + $counts['delete'] + $counts['moved'] + $counts['break_only'] > 0;

        return ['ops' => $ops, 'deletes' => $deletes, 'counts' => $counts, 'changed' => $changed];
    }

    /**
     * The edit script between the two sequences, in document order. Entries
     * carry the RESOLVED rows: ['eq', old, new] / ['del', old] / ['ins', new].
     * Common prefix/suffix are peeled off first; the middle goes through LCS
     * (or the positional fallback past MAX_LCS_CELLS).
     *
     * @return list<array{0: string, 1: array<string, mixed>, 2?: array<string, mixed>}>
     */
    private function diffScript(array $old, array $new): array
    {
        $n = count($old);
        $m = count($new);

        $start = 0;
        while ($start < $n && $start < $m && $old[$start]['text'] === $new[$start]['text']) {
            $start++;
        }

        $endOld = $n;
        $endNew = $m;
        while ($endOld > $start && $endNew > $start && $old[$endOld - 1]['text'] === $new[$endNew - 1]['text']) {
            $endOld--;
            $endNew--;
        }

        $script = [];
        for ($i = 0; $i < $start; $i++) {
            $script[] = ['eq', $old[$i], $new[$i]];
        }

        $a = array_slice($old, $start, $endOld - $start);
        $b = array_slice($new, $start, $endNew - $start);
        array_push($script, ...$this->middleScript($a, $b));

        for ($i = 0; $endOld + $i < $n; $i++) {
            $script[] = ['eq', $old[$endOld + $i], $new[$endNew + $i]];
        }

        return $script;
    }

    /** LCS backtrack over the trimmed middle (or the coarse positional fallback). */
    private function middleScript(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        if ($n === 0 || $m === 0 || $n * $m > self::MAX_LCS_CELLS) {
            // One side empty (a pure insert/delete span), or too big to table:
            // dels then inss — pairRuns() pairs them positionally.
            return [
                ...array_map(fn ($row) => ['del', $row], $a),
                ...array_map(fn ($seg) => ['ins', $seg], $b),
            ];
        }

        // dp[i][j] = LCS length of a[i..] vs b[j..]; ints only, sized by the
        // trimmed middle, so a typical revision tables a handful of cells.
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $a[$i]['text'] === $b[$j]['text']
                    ? 1 + $dp[$i + 1][$j + 1]
                    : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }

        $script = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i]['text'] === $b[$j]['text']) {
                $script[] = ['eq', $a[$i], $b[$j]];
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $script[] = ['del', $a[$i]];
                $i++;
            } else {
                $script[] = ['ins', $b[$j]];
                $j++;
            }
        }
        for (; $i < $n; $i++) {
            $script[] = ['del', $a[$i]];
        }
        for (; $j < $m; $j++) {
            $script[] = ['ins', $b[$j]];
        }

        return $script;
    }

    /**
     * Collapse the script into ops. Each maximal run of del/ins between 'eq'
     * entries pairs positionally: min(d, i) in-place updates (the old row
     * learns the new text, keeping its history), the excess becoming pure
     * deletes or inserts.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array{id: string, text: string}>}
     */
    private function pairRuns(array $script): array
    {
        $ops = [];
        $deletes = [];
        $dels = [];
        $inss = [];

        $flush = function () use (&$ops, &$deletes, &$dels, &$inss) {
            $pairs = min(count($dels), count($inss));
            for ($k = 0; $k < $pairs; $k++) {
                $ops[] = [
                    'op' => 'update',
                    'id' => $dels[$k]['id'],
                    'old_text' => $dels[$k]['text'],
                    'text' => $inss[$k]['text'],
                    'break_after' => $inss[$k]['breakAfter'],
                    'was_generated' => $dels[$k]['has_audio'],
                ];
            }
            foreach (array_slice($dels, $pairs) as $d) {
                $deletes[] = ['id' => $d['id'], 'text' => $d['text']];
            }
            foreach (array_slice($inss, $pairs) as $s) {
                $ops[] = ['op' => 'insert', 'text' => $s['text'], 'break_after' => $s['breakAfter']];
            }
            $dels = [];
            $inss = [];
        };

        foreach ($script as $entry) {
            if ($entry[0] === 'eq') {
                $flush();
                $ops[] = [
                    'op' => 'keep',
                    'id' => $entry[1]['id'],
                    'text' => $entry[1]['text'],
                    'break_after' => $entry[2]['breakAfter'],
                    'break_changed' => $entry[1]['break_after'] !== $entry[2]['breakAfter'],
                    'moved' => false,
                ];
            } elseif ($entry[0] === 'del') {
                $dels[] = $entry[1];
            } else {
                $inss[] = $entry[1];
            }
        }
        $flush();

        return [$ops, $deletes];
    }

    /**
     * Convert insert+delete pairs with IDENTICAL text into moved keeps, in
     * document order, each deleted row consumed at most once — repeated lines
     * ("Why?") can't double-match. A moved keep carries its audio to the new
     * position instead of re-rendering.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array{id: string, text: string}>}
     */
    private function rescueMoves(array $ops, array $deletes): array
    {
        if ($deletes === []) {
            return [$ops, $deletes];
        }

        // text => queue of delete-list indices, consumed in document order.
        $byText = [];
        foreach ($deletes as $i => $d) {
            $byText[$d['text']][] = $i;
        }

        $consumed = [];
        foreach ($ops as $i => $op) {
            if ($op['op'] !== 'insert' || empty($byText[$op['text']])) {
                continue;
            }
            $di = array_shift($byText[$op['text']]);
            $consumed[$di] = true;
            $ops[$i] = [
                'op' => 'keep',
                'id' => $deletes[$di]['id'],
                'text' => $op['text'],
                'break_after' => $op['break_after'],
                // Whether the moved row's break changed doesn't matter for
                // rendering (breaks shape the seam, not the clip); 'moved'
                // already marks the final outdated.
                'break_changed' => false,
                'moved' => true,
            ];
        }

        return [$ops, array_values(array_diff_key($deletes, $consumed))];
    }
}
