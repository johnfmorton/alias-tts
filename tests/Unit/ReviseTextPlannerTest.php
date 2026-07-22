<?php

namespace Tests\Unit;

use App\Services\ReviseTextPlanner;
use PHPUnit\Framework\TestCase;

class ReviseTextPlannerTest extends TestCase
{
    /** @param list<string> $texts */
    private function old(array $texts, string $break = 'sentence'): array
    {
        return array_map(fn (string $t, int $i) => [
            'id' => 'c'.$i,
            'text' => $t,
            'break_after' => $break,
            'has_audio' => true,
        ], $texts, array_keys($texts));
    }

    /** @param list<string> $texts */
    private function new(array $texts, string $break = 'sentence'): array
    {
        return array_map(fn (string $t) => ['text' => $t, 'breakAfter' => $break], $texts);
    }

    private function plan(array $old, array $new): array
    {
        return (new ReviseTextPlanner)->plan($old, $new);
    }

    public function test_identical_sequences_are_all_keeps_and_unchanged(): void
    {
        $plan = $this->plan($this->old(['A.', 'B.', 'C.']), $this->new(['A.', 'B.', 'C.']));

        $this->assertFalse($plan['changed']);
        $this->assertSame(['keep', 'keep', 'keep'], array_column($plan['ops'], 'op'));
        $this->assertSame(['c0', 'c1', 'c2'], array_column($plan['ops'], 'id'));
        $this->assertSame([], $plan['deletes']);
    }

    public function test_single_edit_pairs_into_an_in_place_update(): void
    {
        $plan = $this->plan($this->old(['A.', 'B.', 'C.']), $this->new(['A.', 'B, revised.', 'C.']));

        $this->assertTrue($plan['changed']);
        $this->assertSame(['keep', 'update', 'keep'], array_column($plan['ops'], 'op'));
        // The edited slot keeps the ROW — history and tuning survive the edit.
        $this->assertSame('c1', $plan['ops'][1]['id']);
        $this->assertSame('B.', $plan['ops'][1]['old_text']);
        $this->assertSame('B, revised.', $plan['ops'][1]['text']);
        $this->assertTrue($plan['ops'][1]['was_generated']);
        $this->assertSame([], $plan['deletes']);
    }

    public function test_insertion_shifts_positions_without_touching_neighbors(): void
    {
        $plan = $this->plan($this->old(['A.', 'B.']), $this->new(['A.', 'New.', 'B.']));

        $this->assertSame(['keep', 'insert', 'keep'], array_column($plan['ops'], 'op'));
        $this->assertSame([0, 1, 2], array_column($plan['ops'], 'position'));
        $this->assertSame('c1', $plan['ops'][2]['id']); // B keeps its row at the new position
    }

    public function test_removal_lands_in_the_delete_list(): void
    {
        $plan = $this->plan($this->old(['A.', 'B.', 'C.']), $this->new(['A.', 'C.']));

        $this->assertSame(['keep', 'keep'], array_column($plan['ops'], 'op'));
        $this->assertSame([['id' => 'c1', 'text' => 'B.']], $plan['deletes']);
        $this->assertSame(1, $plan['counts']['delete']);
    }

    public function test_moved_text_is_rescued_as_a_keep_not_a_rerender(): void
    {
        $plan = $this->plan($this->old(['A.', 'B.', 'C.']), $this->new(['B.', 'A.', 'C.']));

        // No update/insert/delete at all: both rows survive, one flagged moved.
        $this->assertSame(['keep', 'keep', 'keep'], array_column($plan['ops'], 'op'));
        $this->assertSame([], $plan['deletes']);
        $this->assertSame(0, $plan['counts']['update'] + $plan['counts']['insert'] + $plan['counts']['delete']);
        $this->assertSame(1, $plan['counts']['moved']);
        $this->assertTrue($plan['changed']); // order changed — the final is outdated
        // Row identity followed the text.
        $byText = array_column($plan['ops'], 'id', 'text');
        $this->assertSame('c0', $byText['A.']);
        $this->assertSame('c1', $byText['B.']);
    }

    public function test_repeated_lines_never_double_match_on_move_rescue(): void
    {
        // Two identical "Why?" rows deleted, only one re-appears: exactly one
        // rescues; the other stays deleted.
        $plan = $this->plan(
            $this->old(['A.', 'Why?', 'B.', 'Why?', 'C.']),
            $this->new(['A.', 'B.', 'C.', 'Why?']),
        );

        $kept = array_filter($plan['ops'], fn ($op) => $op['op'] === 'keep' && $op['text'] === 'Why?');
        $this->assertCount(1, $kept);
        $this->assertCount(1, $plan['deletes']);
        $this->assertSame('Why?', $plan['deletes'][0]['text']);
    }

    public function test_break_only_change_is_a_keep_that_still_marks_change(): void
    {
        $old = $this->old(['A.', 'B.']);
        $new = $this->new(['A.', 'B.']);
        $new[0]['breakAfter'] = 'paragraph'; // the seam grew; the clips didn't change

        $plan = $this->plan($old, $new);

        $this->assertSame(['keep', 'keep'], array_column($plan['ops'], 'op'));
        $this->assertTrue($plan['ops'][0]['break_changed']);
        $this->assertSame('paragraph', $plan['ops'][0]['break_after']);
        $this->assertSame(1, $plan['counts']['break_only']);
        $this->assertTrue($plan['changed']);
    }

    public function test_replace_run_pairs_positionally(): void
    {
        $plan = $this->plan(
            $this->old(['A.', 'B.', 'C.', 'D.']),
            $this->new(['A.', 'X.', 'Y.', 'D.']),
        );

        $this->assertSame(['keep', 'update', 'update', 'keep'], array_column($plan['ops'], 'op'));
        $this->assertSame('c1', $plan['ops'][1]['id']);
        $this->assertSame('c2', $plan['ops'][2]['id']);
    }

    public function test_never_generated_rows_report_was_generated_false(): void
    {
        $old = $this->old(['A.', 'B.']);
        $old[1]['has_audio'] = false;

        $plan = $this->plan($old, $this->new(['A.', 'B2.']));

        $this->assertSame('update', $plan['ops'][1]['op']);
        $this->assertFalse($plan['ops'][1]['was_generated']);
    }

    public function test_positions_are_contiguous_for_every_plan_shape(): void
    {
        $plan = $this->plan(
            $this->old(['A.', 'B.', 'C.', 'D.', 'E.']),
            $this->new(['New0.', 'B.', 'E.', 'C.', 'Tail.']),
        );

        $this->assertSame(range(0, count($plan['ops']) - 1), array_column($plan['ops'], 'position'));
    }
}
