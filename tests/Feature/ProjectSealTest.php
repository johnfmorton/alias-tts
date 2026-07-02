<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\TtsProject;
use App\Models\User;
use App\Models\Voice;
use App\Services\ProjectExportService;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ProjectSealTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tts.provider' => 'fake', 'tts.storage_disk' => 'local']);
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    /** A 2-chunk project, every chunk generated and stitched — i.e. Ready. */
    private function readyProject(): TtsProject
    {
        $voice = Voice::create(['slug' => 'v', 'name' => 'V']);
        $svc = app(ProjectService::class);

        $project = $svc->createFromText(
            title: 'My project',
            voice: $voice,
            text: "This is the first paragraph with plenty of words to stand on its own.\n\n".
                  'This is the second paragraph, also long enough to be its own chunk.',
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: null,
        );

        foreach ($project->chunks()->get() as $chunk) {
            $svc->generateChunk($chunk);
        }
        $svc->rebuild($project);

        return $project->refresh();
    }

    private function finalExt(TtsProject $project): string
    {
        return pathinfo((string) $project->final_audio_path, PATHINFO_EXTENSION) ?: 'mp3';
    }

    /** Open the receipt bytes as a zip; returns the opened archive (caller closes). */
    private function openZip(string $bytes): ZipArchive
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rcpt_').'.zip';
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp) === true, 'receipt is not a valid zip');

        return $zip;
    }

    // ---- Seal ---------------------------------------------------------------

    public function test_seal_requires_authentication(): void
    {
        // Guests are bounced to login; sealing is open to any signed-in, active user.
        $project = $this->readyProject();

        $this->post(route('admin.studio.projects.seal', $project))
            ->assertRedirect(route('login'));
    }

    public function test_seal_records_hash_and_approver(): void
    {
        $admin = $this->admin();
        $project = $this->readyProject();

        $this->actingAs($admin)
            ->postJson(route('admin.studio.projects.seal', $project))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $project->refresh();
        $this->assertTrue($project->isSealed());
        $this->assertNotNull($project->sealed_at);
        $this->assertSame($admin->id, $project->sealed_by_id);
        $this->assertSame($admin->name, $project->sealed_by_name);
        $this->assertSame($admin->email, $project->sealed_by_email);

        $expected = hash('sha256', (string) app(ProjectService::class)->finalAudioBytes($project));
        $this->assertSame($expected, $project->final_sha256);
        $this->assertSame(strlen((string) app(ProjectService::class)->finalAudioBytes($project)), $project->final_bytes);

        $this->assertNotNull($project->sealed_audio_path);
        Storage::disk('local')->assertExists($project->sealed_audio_path);
    }

    public function test_seal_refused_without_a_final(): void
    {
        // A fresh project is Draft (no chunks generated / not rebuilt).
        $voice = Voice::create(['slug' => 'v', 'name' => 'V']);
        $project = app(ProjectService::class)->createFromText(
            title: 'Draft', voice: $voice, text: 'Some text that is long enough to chunk.',
            settings: config('tts.default_voice_settings'), modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'), seed: null,
        );

        $this->actingAs($this->admin())
            ->postJson(route('admin.studio.projects.seal', $project))
            ->assertStatus(422);

        $this->assertFalse($project->refresh()->isSealed());
    }

    // ---- Invalidation -------------------------------------------------------

    public function test_editing_a_chunk_clears_the_seal(): void
    {
        $admin = $this->admin();
        $project = $this->readyProject();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.seal', $project))->assertOk();
        $snapshot = $project->refresh()->sealed_audio_path;

        $chunk = $project->chunks()->orderBy('position')->first();
        app(ProjectService::class)->updateChunkText($chunk, $chunk->text.' A few more words.');

        $project->refresh();
        $this->assertFalse($project->isSealed());
        $this->assertNull($project->sealed_at);
        $this->assertNull($project->final_sha256);
        $this->assertSame(ProjectStatus::Stale, $project->status);
        Storage::disk('local')->assertMissing($snapshot);
    }

    public function test_rebuild_clears_the_seal(): void
    {
        $admin = $this->admin();
        $project = $this->readyProject();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.seal', $project))->assertOk();
        $snapshot = $project->refresh()->sealed_audio_path;

        $this->actingAs($admin)->postJson(route('admin.studio.projects.rebuild', $project))->assertOk();

        $project->refresh();
        $this->assertFalse($project->isSealed());
        // Rebuild produces a fresh Ready final that must be re-approved.
        $this->assertSame(ProjectStatus::Ready, $project->status);
        Storage::disk('local')->assertMissing($snapshot);
    }

    public function test_changing_the_voice_clears_the_seal(): void
    {
        $admin = $this->admin();
        $project = $this->readyProject();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.seal', $project))->assertOk();

        app(ProjectService::class)->changeVoice($project->refresh(), Voice::create(['slug' => 'o', 'name' => 'Other']));

        $this->assertFalse($project->refresh()->isSealed());
    }

    // ---- Receipt ------------------------------------------------------------

    public function test_receipt_requires_admin(): void
    {
        $project = $this->readyProject();

        $this->get(route('admin.studio.projects.receipt', $project))
            ->assertRedirect(route('login'));
    }

    public function test_receipt_on_an_unsealed_project_is_refused(): void
    {
        $project = $this->readyProject();

        $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.receipt', $project))
            ->assertStatus(422);
    }

    public function test_receipt_returns_a_zip_with_the_expected_entries(): void
    {
        $admin = $this->admin();
        $project = $this->readyProject();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.seal', $project))->assertOk();

        $res = $this->actingAs($admin)->get(route('admin.studio.projects.receipt', $project));
        $res->assertOk();
        $this->assertSame('application/zip', $res->headers->get('content-type'));

        $bytes = $res->getContent();
        $this->assertStringStartsWith('PK', $bytes);

        $zip = $this->openZip($bytes);
        foreach (['receipt.html', 'manifest.json', 'final.'.$this->finalExt($project)] as $name) {
            $entry = $zip->getFromName($name);
            $this->assertNotFalse($entry, "missing zip entry: {$name}");
            $this->assertNotSame('', $entry, "empty zip entry: {$name}");
        }
        // The verifier lives inside receipt.html now — no separate page to
        // mistake for the receipt.
        $this->assertFalse($zip->getFromName('verify.html'));
        $zip->close();
    }

    public function test_receipt_page_has_the_verifier_with_the_sealed_hash_baked_in(): void
    {
        $admin = $this->admin();
        $project = $this->readyProject();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.seal', $project))->assertOk();

        $res = $this->actingAs($admin)->get(route('admin.studio.projects.receipt', $project));
        $zip = $this->openZip($res->getContent());
        $receipt = $zip->getFromName('receipt.html');
        $zip->close();

        // The receipt itself is the verifier: a drop zone plus the sealed hash
        // baked into its script, so opening receipt.html offline just works.
        $sha = $project->refresh()->final_sha256;
        $this->assertStringContainsString("var expect = '{$sha}'", $receipt);
        $this->assertStringContainsString('id="drop"', $receipt);
    }

    public function test_receipt_manifest_hash_matches_the_embedded_final(): void
    {
        $admin = $this->admin();
        $project = $this->readyProject();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.seal', $project))->assertOk();

        $res = $this->actingAs($admin)->get(route('admin.studio.projects.receipt', $project));
        $zip = $this->openZip($res->getContent());

        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $final = $zip->getFromName('final.'.$this->finalExt($project));
        $zip->close();

        $this->assertSame($manifest['seal']['final_sha256'], hash('sha256', $final));
        // The receipt script text is the chunk's source text (debugging-by-listening).
        $this->assertSame($project->chunks()->count(), count($manifest['chunks']));
        $this->assertNotEmpty($manifest['chunks'][0]['text']);
    }

    public function test_receipt_records_per_chunk_voice_overrides(): void
    {
        $admin = $this->admin();
        $voice = Voice::create(['slug' => 'v', 'name' => 'V']);
        $override = Voice::create(['slug' => 'lyndon', 'name' => 'Lyndon']);
        $svc = app(ProjectService::class);

        $project = $svc->createFromText(
            title: 'My project',
            voice: $voice,
            text: "First paragraph long enough to stand alone as its own chunk here.\n\n".
                  'Second paragraph also long enough to be its own separate chunk.',
            settings: config('tts.default_voice_settings'),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: null,
        );

        // Voice the first chunk differently from the project, leave the second to inherit.
        $project->chunks()->orderBy('position')->first()->update(['voice_id' => $override->id]);
        foreach ($project->chunks()->get() as $chunk) {
            $svc->generateChunk($chunk);
        }
        $svc->rebuild($project);
        $svc->seal($project->refresh(), $admin);

        $res = $this->actingAs($admin)->get(route('admin.studio.projects.receipt', $project));
        $zip = $this->openZip($res->getContent());
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();

        $byPos = collect($manifest['chunks'])->keyBy('position');
        $this->assertSame('Lyndon', $byPos[0]['voice']);
        $this->assertFalse($byPos[0]['voice_inherited']);
        $this->assertSame('V', $byPos[1]['voice']);
        $this->assertTrue($byPos[1]['voice_inherited']);
    }

    public function test_receipt_serves_the_frozen_snapshot_even_after_the_live_final_is_tampered(): void
    {
        $admin = $this->admin();
        $project = $this->readyProject();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.seal', $project))->assertOk();
        $sealedHash = $project->refresh()->final_sha256;

        // Tamper with the LIVE final on disk; the seal snapshot is a separate file.
        Storage::disk('local')->put($project->final_audio_path, 'TAMPERED-NOT-THE-APPROVED-BYTES');

        $res = $this->actingAs($admin)->get(route('admin.studio.projects.receipt', $project));
        $zip = $this->openZip($res->getContent());
        $final = $zip->getFromName('final.'.$this->finalExt($project));
        $zip->close();

        $this->assertSame($sealedHash, hash('sha256', $final), 'receipt must ship the sealed snapshot, not the live file');
    }

    public function test_export_service_builds_a_self_contained_offline_verifier(): void
    {
        $project = $this->readyProject();
        app(ProjectService::class)->seal($project, $this->admin());

        $zip = $this->openZip(app(ProjectExportService::class)->buildReceiptZip($project));
        $receipt = $zip->getFromName('receipt.html');
        $zip->close();

        $this->assertStringContainsString('crypto.subtle.digest', $receipt);
        $this->assertStringNotContainsString('@vite', $receipt);
        $this->assertStringNotContainsString('<script src', $receipt);
    }

    // ---- Download filenames -------------------------------------------------

    public function test_final_download_filename_carries_the_content_fingerprint(): void
    {
        $project = $this->readyProject();
        $bytes = (string) app(ProjectService::class)->finalAudioBytes($project);
        $short = substr(hash('sha256', $bytes), 0, 8);

        $res = $this->actingAs($this->admin())->get(route('admin.studio.projects.audio', $project));
        $res->assertOk();
        $this->assertStringContainsString(
            'filename="my-project-'.$short.'.',
            (string) $res->headers->get('content-disposition'),
        );
    }

    public function test_receipt_filename_carries_the_sealed_fingerprint(): void
    {
        $admin = $this->admin();
        $project = $this->readyProject();
        app(ProjectService::class)->seal($project, $admin);
        $short = substr((string) $project->final_sha256, 0, 8);

        $res = $this->actingAs($admin)->get(route('admin.studio.projects.receipt', $project));
        $res->assertOk();
        $this->assertStringContainsString(
            'filename="my-project-sealed-'.$short.'.zip"',
            (string) $res->headers->get('content-disposition'),
        );
    }

    // ---- Show page ----------------------------------------------------------

    public function test_show_page_renders_seal_controls_for_a_ready_project(): void
    {
        // Guards the Blade page (incl. the route('verify') data-attr) against errors.
        $project = $this->readyProject();

        $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk()
            ->assertSee('Seal as final')
            // The approver/hash are server-rendered only once sealed.
            ->assertDontSee('approved by');
    }

    public function test_show_page_renders_the_seal_badge_once_sealed(): void
    {
        $admin = $this->admin();
        $project = $this->readyProject();
        app(ProjectService::class)->seal($project, $admin);

        $this->actingAs($admin)
            ->get(route('admin.studio.projects.show', $project))
            ->assertOk()
            ->assertSee('approved by '.$admin->name)
            ->assertSee(substr((string) $project->final_sha256, 0, 12));
    }

    // ---- Verify page --------------------------------------------------------

    public function test_verify_route_is_public_and_offline(): void
    {
        // Served as a static file (BinaryFileResponse), so assert the route is
        // reachable by a guest and the served file is the offline verifier.
        $this->get(route('verify'))->assertOk();
        $this->assertStringContainsString('crypto.subtle.digest', (string) file_get_contents(public_path('verify.html')));
    }
}
