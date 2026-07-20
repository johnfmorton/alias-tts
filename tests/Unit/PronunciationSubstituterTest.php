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

    public function test_a_term_stored_with_an_en_dash_matches_text_written_with_a_hyphen(): void
    {
        // The real-world bug: the detector suggests a typographic en dash while the
        // writer's text uses a plain hyphen. Match should fire, and `applied` must
        // report the ORIGINAL stored term (what the review screen matches on).
        $out = $this->apply('seal every final with SHA-256 provenance', [
            $this->entry("SHA\u{2013}256", 'shah two fifty-six'),
        ]);

        $this->assertSame('seal every final with shah two fifty-six provenance', $out['text']);
        $this->assertSame(["SHA\u{2013}256"], $out['applied']);
    }

    public function test_a_term_stored_with_a_hyphen_matches_text_written_with_an_en_dash(): void
    {
        $out = $this->apply("compute the SHA\u{2013}256 digest", [
            $this->entry('SHA-256', 'shah two fifty-six'),
        ]);

        $this->assertSame('compute the shah two fifty-six digest', $out['text']);
    }

    public function test_dash_folding_leaves_non_matching_dashes_in_the_prose_untouched(): void
    {
        // Only the term's own dash is loosened; a stray em dash in the surrounding
        // sentence (an intentional aside) must survive verbatim.
        $out = $this->apply("Use SHA\u{2013}256 now \u{2014} really", [
            $this->entry('SHA-256', 'shah'),
        ]);

        $this->assertSame("Use shah now \u{2014} really", $out['text']);
        $this->assertSame(['SHA-256'], $out['applied']);
    }

    public function test_dash_folding_composes_with_case_insensitive_matching(): void
    {
        $out = $this->apply("wi\u{2013}fi and WI-FI", [
            $this->entry("Wi\u{2014}Fi", 'wify', 'case_insensitive'),
        ]);

        $this->assertSame('wify and wify', $out['text']);
        $this->assertSame(["Wi\u{2014}Fi"], $out['applied']);
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
