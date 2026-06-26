<?php

namespace Tests\Unit;

use App\Services\Pronunciation\PronunciationSubstituter;
use PHPUnit\Framework\TestCase;

class PronunciationSubstituterTest extends TestCase
{
    /**
     * @param  list<array{term: string, phonetic: string, match_mode?: string}>  $entries
     * @return array{text: string, applied: list<string>}
     */
    private function apply(string $text, array $entries): array
    {
        return (new PronunciationSubstituter)->apply($text, $entries);
    }

    private function entry(string $term, string $phonetic, string $mode = 'case_sensitive'): array
    {
        return ['term' => $term, 'phonetic' => $phonetic, 'match_mode' => $mode];
    }

    public function test_empty_map_is_identity(): void
    {
        $out = $this->apply('Hello DDEV world', []);

        $this->assertSame('Hello DDEV world', $out['text']);
        $this->assertSame([], $out['applied']);
    }

    public function test_replaces_all_occurrences_and_reports_applied(): void
    {
        $out = $this->apply('DDEV and DDEV again', [$this->entry('DDEV', 'dee dev')]);

        $this->assertSame('dee dev and dee dev again', $out['text']);
        $this->assertSame(['DDEV'], $out['applied']);
    }

    public function test_longest_term_wins_over_substring(): void
    {
        $out = $this->apply('Use PostgreSQL here', [
            $this->entry('SQL', 'ess cue ell'),
            $this->entry('PostgreSQL', 'post gres Q L'),
        ]);

        $this->assertSame('Use post gres Q L here', $out['text']);
        $this->assertSame(['PostgreSQL'], $out['applied']);
    }

    public function test_word_boundary_protects_a_substring_inside_a_larger_token(): void
    {
        $out = $this->apply('A NoSQLDB cluster', [$this->entry('SQL', 'ess cue ell')]);

        $this->assertSame('A NoSQLDB cluster', $out['text']);
        $this->assertSame([], $out['applied']);
    }

    public function test_case_sensitive_does_not_match_other_casings(): void
    {
        $out = $this->apply('ddev and DDEV', [$this->entry('DDEV', 'dee dev')]);

        $this->assertSame('ddev and dee dev', $out['text']);
    }

    public function test_case_insensitive_matches_every_casing(): void
    {
        $out = $this->apply('ddev DDEV Ddev', [$this->entry('DDEV', 'dee dev', 'case_insensitive')]);

        $this->assertSame('dee dev dee dev dee dev', $out['text']);
        $this->assertSame(['DDEV'], $out['applied']);
    }

    public function test_symbol_leading_term_matches_standalone_but_not_inside_a_dotted_token(): void
    {
        $entries = [$this->entry('.env', 'dot e n v')];

        $this->assertSame('edit dot e n v please', $this->apply('edit .env please', $entries)['text']);
        $this->assertSame('open foo.env file', $this->apply('open foo.env file', $entries)['text']);
    }

    public function test_symbol_trailing_term(): void
    {
        $out = $this->apply('I love C# a lot', [$this->entry('C#', 'C sharp')]);

        $this->assertSame('I love C sharp a lot', $out['text']);
    }

    public function test_a_replacement_is_never_rescanned(): void
    {
        // The "dev" rule (case-insensitive) must NOT fire on the "dev" produced by
        // rewriting DDEV — a single simultaneous pass replaces the original token
        // only, never its own output.
        $out = $this->apply('DDEV', [
            $this->entry('DDEV', 'dee dev'),
            $this->entry('dev', 'DEVELOPER', 'case_insensitive'),
        ]);

        $this->assertSame('dee dev', $out['text']);
        $this->assertSame(['DDEV'], $out['applied']);
    }
}
