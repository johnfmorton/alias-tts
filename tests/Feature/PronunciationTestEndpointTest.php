<?php

namespace Tests\Feature;

use App\Models\Speech;
use App\Models\User;
use App\Models\Voice;
use App\Services\SpeechService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The "▶ Test" button behind the pronunciation screens: POST a respelling,
 * get back audio of the voice speaking it, so a writer can audition a
 * suggestion before approving it into their dictionary.
 */
class PronunciationTestEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['is_super_admin' => false]);
    }

    private function voiceFor(?User $owner, string $slug): Voice
    {
        return Voice::create([
            'user_id' => $owner?->id,
            'slug' => $slug,
            'name' => ucfirst($slug),
            'reference_audio_path' => "voices/{$slug}.wav",
        ]);
    }

    private function fakeSpeech(): Speech
    {
        $speech = new Speech;
        $speech->mime_type = 'audio/mpeg';

        return $speech;
    }

    public function test_it_speaks_the_respelling_with_the_requested_voice(): void
    {
        $me = $this->user();
        $voice = $this->voiceFor($me, 'narrator');
        $speech = $this->fakeSpeech();

        $this->mock(SpeechService::class, function ($mock) use ($voice, $speech) {
            $mock->shouldReceive('synthesize')->once()
                ->withArgs(function (...$args) use ($voice) {
                    [, $v, $text] = $args;

                    // The respelling inside the carrier sentence (bare short
                    // inputs hard-fail on Chatterbox), with terminal punctuation.
                    return $v->is($voice) && $text === 'Your pronunciation will sound like this: dee dev.';
                })
                ->andReturn($speech);
            $mock->shouldReceive('audioBytes')->once()->andReturn('AUDIO-BYTES');
        });

        $response = $this->actingAs($me)
            ->postJson(route('admin.pronunciations.test'), ['phonetic' => 'dee dev', 'voice' => $voice->id])
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg');

        $this->assertSame('AUDIO-BYTES', $response->getContent());
    }

    public function test_it_keeps_existing_terminal_punctuation(): void
    {
        $me = $this->user();
        $this->voiceFor($me, 'narrator');
        $speech = $this->fakeSpeech();

        $this->mock(SpeechService::class, function ($mock) use ($speech) {
            $mock->shouldReceive('synthesize')->once()
                ->withArgs(fn (...$args) => $args[2] === 'Your pronunciation will sound like this: engine ex!')
                ->andReturn($speech);
            $mock->shouldReceive('audioBytes')->once()->andReturn('AUDIO-BYTES');
        });

        $this->actingAs($me)
            ->postJson(route('admin.pronunciations.test'), ['phonetic' => 'engine ex!'])
            ->assertOk();
    }

    public function test_it_falls_back_to_the_writers_default_voice_when_none_is_given(): void
    {
        $me = $this->user();
        $this->voiceFor($me, 'mine');
        $speech = $this->fakeSpeech();

        // The writer's default = first in their picker order (the same voice the
        // New Project form preselects; with no custom order, a bundled built-in).
        $default = Voice::orderedFor($me->id)->first();
        $this->assertNotNull($default);

        $this->mock(SpeechService::class, function ($mock) use ($default, $speech) {
            $mock->shouldReceive('synthesize')->once()
                ->withArgs(fn (...$args) => $args[1]->is($default))
                ->andReturn($speech);
            $mock->shouldReceive('audioBytes')->once()->andReturn('AUDIO-BYTES');
        });

        $this->actingAs($me)
            ->postJson(route('admin.pronunciations.test'), ['phonetic' => 'dee dev'])
            ->assertOk();
    }

    public function test_it_rejects_another_users_voice_instead_of_silently_swapping(): void
    {
        $me = $this->user();
        $other = $this->user();
        $this->voiceFor($me, 'mine');
        $theirs = $this->voiceFor($other, 'theirs');

        $this->mock(SpeechService::class, function ($mock) {
            $mock->shouldReceive('synthesize')->never();
        });

        $this->actingAs($me)
            ->postJson(route('admin.pronunciations.test'), ['phonetic' => 'dee dev', 'voice' => $theirs->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No voice available to test with.');
    }

    public function test_it_validates_the_respelling_as_json(): void
    {
        $me = $this->user();
        $this->voiceFor($me, 'mine');

        $this->actingAs($me)
            ->postJson(route('admin.pronunciations.test'), ['phonetic' => ''])
            ->assertStatus(422)
            ->assertJsonStructure(['message']);
    }

    public function test_guests_are_redirected(): void
    {
        $this->post(route('admin.pronunciations.test'), ['phonetic' => 'dee dev'])
            ->assertRedirect();
    }
}
