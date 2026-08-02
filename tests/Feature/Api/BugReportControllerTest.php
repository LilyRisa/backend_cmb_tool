<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BugReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    public function test_submit_creates_a_bug_report_without_screenshots(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/bug-reports', [
                'description' => 'Không upload tự động được video tiktok',
                'app_version' => '4.2.1',
            ]);

        $response->assertStatus(201)->assertJsonPath('success', true);
        $this->assertDatabaseHas('bug_reports', ['user_id' => $user->id, 'app_version' => '4.2.1', 'status' => 'pending']);
    }

    public function test_submit_uploads_screenshots_to_local_storage(): void
    {
        // Give the faked public disk its own `url` (as config/filesystems.php does via
        // APP_URL) so the assertion below can actually distinguish a URL minted from
        // the public disk from one minted off the DEFAULT disk — without it both fall
        // back to the identical host-relative "/storage/..." form and a regression to
        // the bare Storage::url() bug would be invisible. Mirrors the pattern in
        // tests/Unit/OpenAiImageServiceTest.php.
        Storage::fake('public', ['url' => 'https://public-disk.test/storage']);
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('screenshot.png');

        $response = $this->withHeaders($this->authHeader($user))
            ->post('/api/bug-reports', [
                'description' => 'Bug with screenshot',
                'screenshots' => [$file],
            ]);

        $response->assertStatus(201);
        $report = \App\Models\BugReport::first();
        $this->assertEquals(1, $report->screenshot_count);
        // The URL must be minted from the same disk the file was written to —
        // Storage::url() would resolve against the DEFAULT disk instead.
        $this->assertStringStartsWith(Storage::disk('public')->url('bug-reports/'), $report->screenshots[0]);
    }

    public function test_submit_derives_stored_extension_from_content_not_client_filename(): void
    {
        // Regression test for C1: the stored filename's extension must be derived
        // from the file's actual (content-sniffed) type, never from the client-
        // supplied filename — otherwise an attacker can control the extension a
        // real image gets stored under.
        //
        // Note: this uses a client filename of "payload.pht" rather than the more
        // obvious "payload.php". Laravel 10.50's `image`/`mimes` validation rules
        // already contain their own client-filename blocklist (`php`, `php3-8`,
        // `phtml`, `phar` — see Illuminate\Validation\Concerns\ValidatesAttributes
        // ::shouldBlockPhpUpload()) that rejects an upload named "payload.php"
        // at the validation layer (422) regardless of this controller's code, which
        // would make a "payload.php" test pass even without this fix and prove
        // nothing about the controller. ".pht" is a well-known PHP-execution
        // bypass extension that is NOT in that upstream blocklist, so it reaches
        // the controller and genuinely exercises the fix: content-sniffed
        // extension() must resolve it to a real image extension, not ".pht".
        Storage::fake('public');
        $user = User::factory()->create();
        // UploadedFile::fake()->image() always generates genuine image bytes, but in
        // test mode Illuminate\Http\Testing\File reports its MIME type from the given
        // filename rather than sniffing the content (unlike real HTTP uploads, where
        // Symfony's UploadedFile::getMimeType() sniffs via finfo). Force the reported
        // MIME type to a real image type here to faithfully simulate a real upload
        // whose bytes content-sniff as an image despite an attacker-chosen filename.
        $file = UploadedFile::fake()->image('payload.pht')->mimeType('image/jpeg');

        $response = $this->withHeaders($this->authHeader($user))
            ->post('/api/bug-reports', [
                'description' => 'Bug with maliciously named screenshot',
                'screenshots' => [$file],
            ]);

        $response->assertStatus(201);

        $storedFiles = Storage::disk('public')->allFiles('bug-reports');
        $this->assertNotEmpty($storedFiles);
        foreach ($storedFiles as $storedFile) {
            $this->assertStringEndsNotWith('.pht', $storedFile);
            $this->assertStringEndsNotWith('.php', $storedFile);
        }
    }

    public function test_submit_requires_description(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/bug-reports', [])
            ->assertStatus(422);
    }

    public function test_submit_rejects_unauthenticated_requests(): void
    {
        $this->postJson('/api/bug-reports', ['description' => 'x'])->assertStatus(401);
    }
}
