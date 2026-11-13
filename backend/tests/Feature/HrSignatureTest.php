<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HrSignatureTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAADwAAAAUCAYAAADRA14pAAAA2klEQVR4nO1YwQ2DMAy0rUzBDt2nI3af7pA1UiHhqo1CsA/apKQnIfGIz3c5QwScUqKRIDQYhAZD+Faj6XJ9PjvxfmM6c8LTi9nWEK9wvTw1ebItN0CsCxGRvYyx2zCSUo9mTYYR4bWa1mMtiHCr6J6S3TSMjqTW1WqOTNnLEbYWIGY/iVIPTzjBSppjJtYjSu+tTT0oaVnjz4/MWFjH+ceDR7hHTK2+NuLo5uVcceF5M3z0G9krCuGw9okLb2hldm8d2oPnhPem1OPxswbWkf414Sj4/8eDzo0Hs4qzCU6LdigAAAAASUVORK5CYII=';

    public function test_upload_versions_the_signed_in_accounts_private_signature(): void
    {
        Storage::fake('local');
        $ceo = $this->signIn('ceo');
        $this->getJson('/api/v1/me/hr-signature')->assertOk()->assertJsonPath('data.signature', null);
        for ($version = 1; $version <= 2; $version++) {
            $image = UploadedFile::fake()->createWithContent('signature.png', base64_decode(self::PNG));
            $this->postJson('/api/v1/me/hr-signature', ['file' => $image])
                ->assertCreated()->assertJsonPath('data.signature.version', $version)
                ->assertJsonMissingPath('data.signature.storage_path');
        }

        $images = DB::table('hr_signature_images')->where('owner_id', $ceo->id)->orderBy('version')->pluck('storage_path')->all();
        $this->assertCount(2, $images);
        Storage::disk('local')->assertExists($images);
        $this->assertDatabaseHas('audit_log', ['user_id' => $ceo->id, 'entity_type' => 'hr_signature_images', 'event' => 'REPLACED']);

        $this->get('/api/v1/me/hr-signature/file')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->signIn('hr');
        $this->get('/api/v1/me/hr-signature/file')->assertNotFound();
        $this->get("/api/v1/me/hr-signature/file?owner_id={$ceo->id}")->assertNotFound();
        $this->postJson('/api/v1/me/hr-signature', [
            'file' => UploadedFile::fake()->createWithContent('header.png', "\x89PNG\r\n\x1a\n"),
        ])->assertUnprocessable();
        $truncated = substr(base64_decode(self::PNG), 0, 33);
        $this->assertSame('image/png', (new \finfo(FILEINFO_MIME_TYPE))->buffer($truncated));
        $this->assertIsArray(getimagesizefromstring($truncated));
        $this->postJson('/api/v1/me/hr-signature', [
            'file' => UploadedFile::fake()->createWithContent('truncated.png', $truncated),
        ])->assertUnprocessable();
        $tooWide = substr_replace($truncated, pack('N', 4_000_001), 16, 4);
        $this->postJson('/api/v1/me/hr-signature', [
            'file' => UploadedFile::fake()->createWithContent('oversized.png', $tooWide),
        ])->assertUnprocessable();
        $this->getJson('/api/v1/me/hr-signature')->assertOk()->assertJsonPath('data.signature', null);
        $this->postJson('/api/v1/me/hr-signature', ['file' => UploadedFile::fake()->createWithContent('wrong.png', 'not an image')])
            ->assertUnprocessable();
        $this->signIn('manager');
        $this->get('/api/v1/me/hr-signature/file')->assertForbidden();
        $this->signIn('developer');
        $this->getJson('/api/v1/me/hr-signature')->assertForbidden();
    }
}
