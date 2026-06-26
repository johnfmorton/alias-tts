"""The offline self-test path of the smoke script must stay green."""

from __future__ import annotations

from genblaze_runner.smoke import main, run_offline


def test_offline_smoke_passes():
    outcome = run_offline(text="One sentence. Two sentences.", max_concurrency=1)
    assert outcome.passed is True
    assert outcome.result is not None
    assert len(outcome.result.chunks) == 2
    assert outcome.result.final.url.startswith("file://")
    assert outcome.result.manifest.verify() is True


def test_offline_smoke_simulated_reroll():
    outcome = run_offline(text="Only one sentence.", seed=1, max_concurrency=1, simulate_reroll=True)
    assert outcome.passed is True
    assert outcome.result.reroll_count == 1
    assert outcome.result.chunks[0].attempts == 2


def test_main_offline_returns_zero():
    assert main(["--offline", "--text", "Hello there. Goodbye now."]) == 0
