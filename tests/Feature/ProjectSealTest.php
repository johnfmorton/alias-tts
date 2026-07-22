<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Models\TtsProject;
use App\Models\User;
use App\Models\Voice;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    /** The in-zip audio name after sealing — matches the .zip and its folder. */
    private function sealedAudioName(TtsProject $project): string
    {
        return $project->refresh()->sealedBaseName().'.'.$this->finalExt($project);
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

    public function test_unseal_drops_an_approval_made_by_mistake(): void
    {
        $admin = $this->admin();
        $project = $this->readyProject();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.seal', $project))->assertOk();
        $snapshot = $project->refresh()->sealed_audio_path;
        $this->assertTrue($project->isSealed());

        $this->actingAs($admin)
            ->deleteJson(route('admin.studio.projects.unseal', $project))
            ->assertOk()
            ->assertJsonPath('project_status', 'ready');

        $project->refresh();
        $this->assertFalse($project->isSealed());
        $this->assertNull($project->sealed_at);
        $this->assertNull($project->final_sha256);
        // The audio itself survives — the project can be edited or re-approved.
        $this->assertSame(ProjectStatus::Ready, $project->status);
        Storage::disk('local')->assertMissing($snapshot);
    }

    public function test_unseal_requires_authentication(): void
    {
        $project = $this->readyProject();

        $this->delete(route('admin.studio.projects.unseal', $project))
            ->assertRedirect(route('login'));
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

        $bytes = $res->streamedContent();
        $this->assertStringStartsWith('PK', $bytes);

        $zip = $this->openZip($bytes);
        foreach (['receipt.html', 'manifest.json', $this->sealedAudioName($project)] as $name) {
            $entry = $zip->getFromName($name);
            $this->assertNotFalse($entry, "missing zip entry: {$name}");
            $this->assertNotSame('', $entry, "empty zip entry: {$name}");
        }
        // Verification is server-side now (the hosted /verify page) — the zip
        // ships no client-side verifier page to mistake for the receipt.
        $this->assertFalse($zip->getFromName('verify.html'));

        // The audio is named for the project + fingerprint (matching the .zip and
        // its folder), not a bare "final.mp3".
        $this->assertSame('my-project-sealed-'.substr((string) $project->final_sha256, 0, 8).'.'.$this->finalExt($project), $this->sealedAudioName($project));
        $this->assertFalse($zip->getFromName('final.'.$this->finalExt($project)), 'audio must not be the bare final.<ext>');
        $zip->close();
    }

    public function test_receipt_shows_the_sealed_hash_and_links_to_the_hosted_verifier(): void
    {
        $admin = $this->admin();
        $project = $this->readyProject();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.seal', $project))->assertOk();

        $res = $this->actingAs($admin)->get(route('admin.studio.projects.receipt', $project));
        $zip = $this->openZip($res->streamedContent());
        $receipt = $zip->getFromName('receipt.html');
        $zip->close();

        $sha = $project->refresh()->final_sha256;
        // The receipt prints the sealed fingerprint and points at the hosted,
        // server-side verifier — no client-side hashing in the page.
        $this->assertStringContainsString($sha, $receipt);
        $this->assertStringContainsString(route('verify'), $receipt);
        $this->assertStringNotContainsString('crypto.subtle', $receipt);
        $this->assertStringNotContainsString('id="drop"', $receipt);
        // The page names the audio it verifies — the same project+fingerprint name
        // as the file in the zip, so the instructions point at the real filename.
        $this->assertStringContainsString($this->sealedAudioName($project), $receipt);
    }

    public function test_receipt_manifest_hash_matches_the_embedded_final(): void
    {
        $admin = $this->admin();
        $project = $this->readyProject();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.seal', $project))->assertOk();

        $res = $this->actingAs($admin)->get(route('admin.studio.projects.receipt', $project));
        $zip = $this->openZip($res->streamedContent());

        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $final = $zip->getFromName($this->sealedAudioName($project));
        $zip->close();

        $this->assertSame($manifest['seal']['final_sha256'], hash('sha256', $final));
        // The receipt script text is the chunk's source text (debugging-by-listening).
        $this->assertSame($project->chunks()->count(), count($manifest['chunks']));
        $this->assertNotEmpty($manifest['chunks'][0]['text']);
    }

    public function test_receipt_labels_skipped_chunks(): void
    {
        $admin = $this->admin();
        $svc = app(ProjectService::class);
        $project = $this->readyProject();

        // Skip the first chunk AFTER it was generated, rebuild so the final
        // reflects the skip, then seal — the receipt shows the whole project
        // with the skipped chunk labeled, not silently omitted.
        $chunk = $project->chunks()->orderBy('position')->first();
        $svc->setChunkSkipped($chunk, true);
        $svc->rebuild($project->refresh());
        $this->actingAs($admin)->postJson(route('admin.studio.projects.seal', $project))->assertOk();

        $res = $this->actingAs($admin)->get(route('admin.studio.projects.receipt', $project));
        $zip = $this->openZip($res->streamedContent());
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $receipt = $zip->getFromName('receipt.html');
        $zip->close();

        $this->assertCount(2, $manifest['chunks']); // the skipped chunk is still listed
        $this->assertTrue($manifest['chunks'][0]['skipped']);
        $this->assertFalse($manifest['chunks'][1]['skipped']);
        $this->assertNotEmpty($manifest['chunks'][0]['text']); // its script text survives

        // The label appears exactly once — under the skipped chunk only.
        $this->assertSame(1, substr_count($receipt, 'skipped — not in final audio'));
    }

    public function test_receipt_prints_the_selected_takes_text_not_the_current_chunk_text(): void
    {
        // A take can be re-selected after the chunk's text was edited, so the
        // chunk's current text (v2) may not be what the sealed audio actually says
        // (v1). The receipt must print the SELECTED take's snapshotted text.
        $admin = $this->admin();
        $svc = app(ProjectService::class);
        $project = $this->readyProject();

        $chunk = $project->chunks()->orderBy('position')->first();
        $originalText = $chunk->text;
        $originalTake = $chunk->takes()->where('audio_path', $chunk->audio_path)->first();
        $this->assertSame($originalText, $originalTake->text); // snapshotted at generate

        // Edit the text (chunk goes Stale; the take's audio + text are untouched),
        // then re-select the original take as the chunk's audio.
        $svc->updateChunkText($chunk->refresh(), 'These are completely different words for version two of the chunk.');
        $svc->selectTake($originalTake->refresh());

        $svc->rebuild($project->refresh());
        $svc->seal($project->refresh(), $admin);

        $res = $this->actingAs($admin)->get(route('admin.studio.projects.receipt', $project));
        $zip = $this->openZip($res->streamedContent());
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();

        $row = collect($manifest['chunks'])->firstWhere('position', $chunk->position);
        $this->assertSame($originalText, $row['text']);
        $this->assertStringNotContainsString('version two', $row['text']);
    }

    public function test_receipt_falls_back_to_chunk_text_for_a_legacy_take_without_a_snapshot(): void
    {
        // Pre-existing takes have no text snapshot (null). The receipt must still
        // print something sensible — the chunk's current text — not blow up.
        $admin = $this->admin();
        $project = $this->readyProject();

        $chunk = $project->chunks()->orderBy('position')->first();
        $chunk->takes()->update(['text' => null]); // simulate a pre-migration take

        app(ProjectService::class)->seal($project->refresh(), $admin);

        $res = $this->actingAs($admin)->get(route('admin.studio.projects.receipt', $project));
        $zip = $this->openZip($res->streamedContent());
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();

        $row = collect($manifest['chunks'])->firstWhere('position', $chunk->position);
        $this->assertSame($chunk->text, $row['text']);
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
        $zip = $this->openZip($res->streamedContent());
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
        $zip = $this->openZip($res->streamedContent());
        $final = $zip->getFromName($this->sealedAudioName($project));
        $zip->close();

        $this->assertSame($sealedHash, hash('sha256', $final), 'receipt must ship the sealed snapshot, not the live file');
    }

    public function test_export_service_receipt_has_no_client_side_verifier(): void
    {
        $admin = $this->admin();
        $project = $this->readyProject();
        app(ProjectService::class)->seal($project, $admin);

        $res = $this->actingAs($admin)->get(route('admin.studio.projects.receipt', $project));
        $zip = $this->openZip($res->streamedContent());
        $receipt = $zip->getFromName('receipt.html');
        $zip->close();

        // The receipt is a self-contained provenance record that links out to the
        // server-side verifier — it carries no script and no in-browser hashing.
        $this->assertStringNotContainsString('crypto.subtle', $receipt);
        $this->assertStringNotContainsString('<script', $receipt);
        $this->assertStringNotContainsString('@vite', $receipt);
        $this->assertStringContainsString(route('verify'), $receipt);
    }

    // ---- Download archive (receipt package + every clip) --------------------

    public function test_archive_requires_authentication(): void
    {
        $project = $this->readyProject();

        $this->get(route('admin.studio.projects.archive', $project))
            ->assertRedirect(route('login'));
    }

    public function test_archive_on_an_unsealed_project_is_refused(): void
    {
        $project = $this->readyProject();

        $this->actingAs($this->admin())
            ->get(route('admin.studio.projects.archive', $project))
            ->assertStatus(422);
    }

    public function test_archive_contains_the_receipt_package_and_every_clip(): void
    {
        $admin = $this->admin();
        $project = $this->readyProject();
        // A second take on the first chunk — the archive must ship BOTH, with
        // the selected one (the newest; re-roll selects its render) marked.
        $chunk = $project->chunks()->orderBy('position')->first();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.chunks.generate', [$project, $chunk]))->assertOk();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.rebuild', $project))->assertOk();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.seal', $project))->assertOk();
        $project->refresh();

        $res = $this->actingAs($admin)->get(route('admin.studio.projects.archive', $project));
        $res->assertOk();
        $this->assertSame('application/zip', $res->headers->get('content-type'));
        $this->assertStringContainsString(
            'filename='.$project->sealedBaseName().'-archive.zip',
            (string) $res->headers->get('content-disposition'),
        );

        $zip = $this->openZip($res->streamedContent());
        foreach ([
            'receipt.html',
            'manifest.json',
            $this->sealedAudioName($project),
            'clips/chunk-01/take-01.wav',
            'clips/chunk-01/take-02-selected.wav',
            'clips/chunk-02/take-01-selected.wav',
        ] as $name) {
            $entry = $zip->getFromName($name);
            $this->assertNotFalse($entry, "missing zip entry: {$name}");
            $this->assertNotSame('', $entry, "empty zip entry: {$name}");
        }

        // The selected clips are the exact bytes the chunks play.
        $disk = Storage::disk('local');
        $chunks = $project->chunks()->orderBy('position')->get();
        $this->assertSame($disk->get($chunks[0]->audio_path), $zip->getFromName('clips/chunk-01/take-02-selected.wav'));
        $this->assertSame($disk->get($chunks[1]->audio_path), $zip->getFromName('clips/chunk-02/take-01-selected.wav'));

        // The manifest lists every clip with a hash that matches its zipped bytes.
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $this->assertCount(3, $manifest['clips']);
        foreach ($manifest['clips'] as $clip) {
            $this->assertSame($clip['sha256'], hash('sha256', $zip->getFromName($clip['file'])), $clip['file']);
        }
        $this->assertSame(
            ['clips/chunk-01/take-02-selected.wav', 'clips/chunk-02/take-01-selected.wav'],
            array_values(array_column(array_filter($manifest['clips'], fn ($c) => $c['selected']), 'file')),
        );
        // The receipt package inside is unchanged: the manifest still opens with
        // the same seal record the receipt zip carries.
        $this->assertSame($project->final_sha256, $manifest['seal']['final_sha256']);
        $zip->close();
    }

    public function test_archive_after_cleanup_ships_only_the_selected_clips(): void
    {
        $admin = $this->admin();
        $project = $this->readyProject();
        $chunk = $project->chunks()->orderBy('position')->first();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.chunks.generate', [$project, $chunk]))->assertOk();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.rebuild', $project))->assertOk();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.seal', $project))->assertOk();

        $this->actingAs($admin)->post(route('admin.studio.projects.cleanup', $project))
            ->assertSessionHas('success', 'Project cleaned up — 1 unused take was removed.');

        $res = $this->actingAs($admin)->get(route('admin.studio.projects.archive', $project));
        $res->assertOk();

        $zip = $this->openZip($res->streamedContent());
        $clips = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_starts_with($name, 'clips/')) {
                $clips[] = $name;
            }
        }
        $zip->close();

        sort($clips);
        $this->assertSame([
            'clips/chunk-01/take-01-selected.wav',
            'clips/chunk-02/take-01-selected.wav',
        ], $clips);
    }

    public function test_archive_lists_a_take_whose_file_is_missing_instead_of_dropping_it(): void
    {
        $admin = $this->admin();
        $project = $this->readyProject();
        $chunk = $project->chunks()->orderBy('position')->first();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.chunks.generate', [$project, $chunk]))->assertOk();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.rebuild', $project))->assertOk();
        $this->actingAs($admin)->postJson(route('admin.studio.projects.seal', $project))->assertOk();

        // The non-selected take's file vanishes from storage out-of-band.
        $chunk->refresh();
        $orphan = $chunk->takes()->where('audio_path', '!=', $chunk->audio_path)->firstOrFail();
        Storage::disk('local')->delete($orphan->audio_path);

        $res = $this->actingAs($admin)->get(route('admin.studio.projects.archive', $project));
        $res->assertOk();

        $zip = $this->openZip($res->streamedContent());
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();

        // Still listed — with a null file/hash — so the archive never silently
        // under-reports the takes that existed.
        $this->assertCount(3, $manifest['clips']);
        $missing = array_values(array_filter($manifest['clips'], fn ($c) => $c['file'] === null));
        $this->assertCount(1, $missing);
        $this->assertNull($missing[0]['sha256']);
        $this->assertFalse($missing[0]['selected']);
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
            'filename=my-project-sealed-'.$short.'.zip',
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
            ->assertSee('Approve as final')
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

    // ---- Verify page (public; local-first hashing, opt-in upload) -----------

    public function test_verify_page_is_public_and_hashes_locally(): void
    {
        // Reachable by a guest and wired to fingerprint the file IN THE BROWSER
        // (Web Crypto), handing off only the hash via ?sha=…&local=1.
        $content = $this->get(route('verify'))
            ->assertOk()
            ->assertSee('Verify file')
            ->getContent();

        $this->assertStringContainsString('crypto.subtle', $content);   // local hashing
        $this->assertStringContainsString('local=1', $content);         // fingerprint-only handoff
    }

    public function test_verify_uploads_are_disabled_by_default(): void
    {
        // Secure by default: no upload form on the page, and the POST endpoint
        // 404s — so there is no file-upload attack surface.
        $content = $this->get(route('verify'))->assertOk()->getContent();
        $this->assertStringNotContainsString('multipart/form-data', $content);

        $upload = UploadedFile::fake()->createWithContent('x.mp3', 'bytes');
        $this->post(route('verify.check'), ['file' => $upload])->assertNotFound();
    }

    public function test_verify_by_local_hash_reports_verified(): void
    {
        $project = $this->readyProject();
        $admin = $this->admin();
        app(ProjectService::class)->seal($project, $admin);
        $sha = $project->refresh()->final_sha256;

        // Browser hashed the file locally and redirected with local=1 → a full
        // "Verified" verdict (not the bare record lookup), with provenance, noting
        // the file was NOT uploaded.
        $this->get(route('verify', ['sha' => $sha, 'local' => 1]))
            ->assertOk()
            ->assertSee('Verified')
            ->assertSee('Fingerprinted in your browser')  // privacy note: no upload
            ->assertSee($admin->name)
            ->assertSee('plenty of words');               // per-chunk take text retained
    }

    public function test_verify_by_local_hash_no_match_reports_no_match(): void
    {
        $this->get(route('verify', ['sha' => str_repeat('a', 64), 'local' => 1]))
            ->assertOk()
            ->assertSee('No match');
    }

    public function test_verify_upload_matches_a_sealed_final_when_enabled(): void
    {
        config(['tts.verify_allow_upload' => true]);

        $project = $this->readyProject();
        app(ProjectService::class)->seal($project, $this->admin());
        $bytes = (string) app(ProjectService::class)->sealedAudioBytes($project->refresh());

        // With the upload fallback enabled, a guest uploads the approved bytes;
        // the server hashes them and matches.
        $upload = UploadedFile::fake()->createWithContent($this->sealedAudioName($project), $bytes);

        $this->post(route('verify.check'), ['file' => $upload])
            ->assertOk()
            ->assertSee('Verified')                       // the match verdict
            ->assertSee('Approved by')                    // seal panel (approver)
            ->assertSee('plenty of words');               // per-chunk take text is retained
    }

    public function test_verify_upload_that_does_not_match_reports_no_match_when_enabled(): void
    {
        config(['tts.verify_allow_upload' => true]);

        $project = $this->readyProject();
        app(ProjectService::class)->seal($project, $this->admin());

        $upload = UploadedFile::fake()->createWithContent('not-the-final.mp3', 'these bytes were never approved');

        $this->post(route('verify.check'), ['file' => $upload])
            ->assertOk()
            ->assertSee('No match');
    }

    public function test_verify_by_fingerprint_shows_the_sealed_record(): void
    {
        $project = $this->readyProject();
        $admin = $this->admin();
        app(ProjectService::class)->seal($project, $admin);
        $sha = $project->refresh()->final_sha256;

        // A plain ?sha= link (no local=1) — e.g. from a receipt — opens the
        // authoritative record and invites the holder to check their copy.
        $this->get(route('verify', ['sha' => $sha]))
            ->assertOk()
            ->assertSee('sealed approval exists')
            ->assertSee($admin->name)                     // approver name (not email)
            ->assertSee('plenty of words')                // per-chunk take text
            ->assertSee('server-confirmed');              // snapshot integrity re-hash
    }

    public function test_verify_by_unknown_fingerprint_reports_no_record(): void
    {
        $this->get(route('verify', ['sha' => str_repeat('a', 64)]))
            ->assertOk()
            ->assertSee('No approval found');
    }
}
