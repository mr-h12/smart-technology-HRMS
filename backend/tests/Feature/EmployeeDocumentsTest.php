<?php

namespace Tests\Feature;

use App\Modules\Employees\Infrastructure\Eloquent\EmployeeDocument;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class EmployeeDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->signIn('hr');
        $this->travelTo('2026-11-03 10:00:00');
    }

    protected function tearDown(): void
    {
        array_map(fn (string $p) => @unlink($p), $this->tmp);
        parent::tearDown();
    }

    private function employee(): string
    {
        return $this->postJson('/api/v1/employees', ['name_ar' => 'موظف', 'gender' => 'male', 'type' => 'engineer', 'effective_from' => '2026-01-01', 'salary' => '9000'])->json('data.id');
    }

    public function test_the_table_has_the_file_columns_and_no_received_date(): void
    {
        $this->assertFalse(Schema::hasColumn('employee_documents', 'received_at'));
        $this->assertFalse(Schema::hasColumn('employee_documents', 'path'));
        $this->assertTrue(Schema::hasColumns('employee_documents', ['original_name', 'mime', 'size_bytes']));
        $this->expectException(QueryException::class);
        EmployeeDocument::create(['employee_id' => $this->employee(), 'kind' => 'other', 'original_name' => 'a.pdf', 'mime' => 'application/pdf', 'size_bytes' => 0]);
    }

    /** Real bytes: UploadedFile::fake() reports a mime from the name and would skip the sniff. */
    private function file(string $name, string $bytes = self::PDF): UploadedFile
    {
        $path = $this->tmp[] = (string) tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function upload(string $emp, array $over = []): TestResponse
    {
        return $this->post("/api/v1/employees/$emp/documents", ['file' => $this->file('عقد.pdf'), 'kind' => 'work_stub', ...$over], ['Accept' => 'application/json']);
    }

    public function test_upload_stores_the_file_and_a_row(): void
    {
        $emp = $this->employee();
        $res = $this->upload($emp)->assertCreated()
            ->assertJsonPath('data.kind', 'work_stub')
            ->assertJsonPath('data.original_name', 'عقد.pdf')
            ->assertJsonPath('data.mime', 'application/pdf')
            ->assertJsonPath('data.size_bytes', strlen(self::PDF))
            ->assertJsonPath('data.uploader_name', 'hr')
            ->assertJsonMissingPath('data.received_at')
            ->assertJsonMissingPath('data.path');
        $this->assertSame(['id', 'kind', 'expires_at', 'original_name', 'mime', 'size_bytes', 'note', 'created_at', 'uploader_name'], array_keys($res->json('data')));
        $id = $res->json('data.id');
        Storage::disk('local')->assertExists("employee-documents/$emp/{$id}.pdf");
        $this->assertStringNotContainsString('employee-documents/', (string) $res->getContent());
        $audit = DB::table('audit_log')->where(['entity_type' => 'employee_documents', 'event' => 'CREATED'])->sole();
        $this->assertSame($id, $audit->entity_id);
        $this->assertArrayHasKey('kind', $new = json_decode($audit->new_values, true));
        foreach (['original_name', 'mime', 'size_bytes'] as $key) {
            $this->assertArrayHasKey($key, $new);
        }
    }

    public function test_the_type_is_checked_against_the_bytes(): void
    {
        $emp = $this->employee();
        foreach ([
            $this->file('x.png', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
            $this->file('x.pdf', '<!DOCTYPE html><html><body>x</body></html>'),
            $this->file('x.jpg', 'plain text'),
            $this->file('x.pdf', ''),
        ] as $file) {
            $this->upload($emp, ['file' => $file])->assertStatus(422)
                ->assertJsonPath('error.details.0.field', 'file')
                ->assertJsonPath('error.details.0.message', __('errors.document_type'));
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, EmployeeDocument::withTrashed()->count());
    }

    public function test_png_bytes_are_accepted_and_stored_as_png(): void
    {
        $emp = $this->employee();
        $res = $this->upload($emp, ['file' => $this->file('photo.webp', (string) base64_decode(self::PNG))])->assertCreated()
            ->assertJsonPath('data.mime', 'image/png');
        Storage::disk('local')->assertExists("employee-documents/$emp/{$res->json('data.id')}.png");
    }

    public function test_too_large_is_422_with_the_size_message(): void
    {
        $emp = $this->employee();
        $tmp = $this->tmp[] = (string) tempnam(sys_get_temp_dir(), 'doc');
        foreach ([
            UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf'),
            new UploadedFile($tmp, 'big.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true), // PHP dropped it between its two limits
        ] as $file) {
            $this->upload($emp, ['file' => $file])->assertStatus(422)
                ->assertJsonPath('error.details.0.field', 'file')
                ->assertJsonPath('error.details.0.message', __('errors.document_too_large', ['max' => 10]));
        }
    }

    public function test_the_file_name_loses_control_and_bidi_characters(): void
    {
        $emp = $this->employee();
        $name = fn (string $n) => $this->upload($emp, ['file' => $this->file($n)])->assertCreated()->json('data.original_name');
        $this->assertSame('abc.pdf', $name("a\u{202E}b\u{0007}c.pdf"));
        $this->assertSame('document', $name("\u{202E}\u{0007}"));
        $this->assertSame(255, mb_strlen($name(str_repeat('ع', 300).'.pdf')));
    }

    public function test_a_failed_insert_leaves_no_file(): void
    {
        $emp = $this->employee();
        EmployeeDocument::creating(fn () => throw new RuntimeException('boom'));
        $this->upload($emp)->assertStatus(500);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_replace_soft_deletes_the_old_row_and_keeps_its_file(): void
    {
        $emp = $this->employee();
        $a = $this->upload($emp)->assertCreated()->json('data.id');
        $b = $this->upload($emp, ['replaces' => $a])->assertCreated()->json('data.id');
        $old = EmployeeDocument::withTrashed()->findOrFail($a);
        $this->assertNotNull($old->deleted_at);
        Storage::disk('local')->assertExists($old->path());
        $this->assertNull(EmployeeDocument::findOrFail($b)->deleted_at);
        $this->assertTrue(DB::table('audit_log')->where(['entity_id' => $b, 'event' => 'CREATED'])->exists());
        $this->assertTrue(DB::table('audit_log')->where(['entity_id' => $a, 'event' => 'DELETED'])->exists());
    }

    public function test_replaces_must_be_a_live_document_of_this_employee(): void
    {
        $emp = $this->employee();
        $other = $this->employee();
        $foreign = $this->upload($other)->assertCreated()->json('data.id');
        $a = $this->upload($emp)->assertCreated()->json('data.id');
        $b = $this->upload($emp, ['replaces' => $a])->assertCreated()->json('data.id');

        $this->upload($emp, ['replaces' => $foreign])->assertNotFound();
        $this->upload($emp, ['replaces' => $a])->assertNotFound();

        $expected = array_map(fn (string $id) => EmployeeDocument::withTrashed()->findOrFail($id)->path(), [$foreign, $a, $b]);
        $actual = Storage::disk('local')->allFiles();
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
        $this->assertSame(3, EmployeeDocument::withTrashed()->count());
    }

    public function test_only_document_editors_upload(): void
    {
        $emp = $this->employee();
        $this->signIn('ceo');
        $this->upload($emp)->assertStatus(403)->assertJsonPath('error.code', 'permission_denied');
        $this->signIn('manager');
        $this->upload($emp)->assertStatus(403);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, EmployeeDocument::withTrashed()->count());
    }

    public function test_details_are_edited_and_audited(): void
    {
        $emp = $this->employee();
        $id = $this->upload($emp)->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/employees/$emp/documents/$id", ['kind' => 'other', 'expires_at' => '2027-01-31', 'note' => 'نسخة'])->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.kind', 'other')
            ->assertJsonPath('data.expires_at', '2027-01-31')
            ->assertJsonPath('data.note', 'نسخة');
        $audit = DB::table('audit_log')->where(['entity_id' => $id, 'event' => 'UPDATED'])->sole();
        foreach (['old_values', 'new_values'] as $col) {
            $keys = array_keys(json_decode($audit->$col, true));
            sort($keys);
            $this->assertSame(['expires_at', 'kind', 'note'], $keys);
        }
        $this->patchJson("/api/v1/employees/$emp/documents/$id", ['kind' => 'bogus'])->assertStatus(422);
    }

    public function test_delete_hides_the_row_keeps_the_file_and_is_audited(): void
    {
        $emp = $this->employee();
        $id = $this->upload($emp)->assertCreated()->json('data.id');
        $this->deleteJson("/api/v1/employees/$emp/documents/$id")->assertNoContent();
        $doc = EmployeeDocument::withTrashed()->findOrFail($id);
        $this->assertNotNull($doc->deleted_at);
        Storage::disk('local')->assertExists($doc->path());
        $this->assertTrue(DB::table('audit_log')->where(['entity_id' => $id, 'event' => 'DELETED'])->exists());
        $this->getJson("/api/v1/employees/$emp")->assertOk()->assertJsonPath('data.documents', []);
    }

    public function test_another_employees_document_is_404(): void
    {
        $a = $this->employee();
        $b = $this->employee();
        $id = $this->upload($b)->assertCreated()->json('data.id');
        $before = EmployeeDocument::findOrFail($id)->getAttributes();
        $this->patchJson("/api/v1/employees/$a/documents/$id", ['note' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/employees/$a/documents/$id")->assertNotFound();
        $this->assertSame($before, EmployeeDocument::findOrFail($id)->getAttributes());
    }

    public function test_only_document_editors_edit_and_delete(): void
    {
        [$manager, $emp] = $this->managed();
        $id = $this->upload($emp)->assertCreated()->json('data.id');
        $this->actingAs($manager, 'api');
        $this->patchJson("/api/v1/employees/$emp/documents/$id", ['note' => 'x'])->assertStatus(403)->assertJsonPath('error.code', 'permission_denied');
        $this->deleteJson("/api/v1/employees/$emp/documents/$id")->assertStatus(403)->assertJsonPath('error.code', 'permission_denied');
        $this->assertNull(EmployeeDocument::findOrFail($id)->deleted_at);
    }

    /** @return array{User, string} a manager and an engineer in their reach */
    private function managed(): array
    {
        $manager = $this->makeUser('manager');
        $emp = $this->employee();
        $this->postJson("/api/v1/employees/$emp/manager-assignments", ['manager_id' => $manager->id, 'effective_from' => '2026-01-01'])->assertCreated();

        return [$manager, $emp];
    }

    private function fileOf(string $emp, string $id): TestResponse
    {
        return $this->get("/api/v1/employees/$emp/documents/$id/file", ['Accept' => 'application/json']);
    }

    public function test_the_file_is_served_with_safe_headers(): void
    {
        $emp = $this->employee();
        $id = $this->upload($emp)->assertCreated()->json('data.id');
        $res = $this->fileOf($emp, $id)->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $res->headers->get('Cache-Control'));
        $this->assertSame(self::PDF, $res->streamedContent());
    }

    public function test_the_download_name_uses_the_detected_extension(): void
    {
        $emp = $this->employee();
        $name = fn (string $n) => (string) $this->fileOf($emp, $this->upload($emp, ['file' => $this->file($n)])->assertCreated()->json('data.id'))
            ->assertOk()->headers->get('Content-Disposition');
        $html = $name('x.html');
        $this->assertStringContainsString('x.pdf', $html);
        $this->assertStringNotContainsString('.html', $html);
        $this->assertStringContainsString('document.pdf', $name("\u{202E}"));
        $this->assertStringContainsString('document.pdf', $name('.pdf'));
    }

    public function test_a_deleted_document_is_404_on_file(): void
    {
        $emp = $this->employee();
        $id = $this->upload($emp)->assertCreated()->json('data.id');
        $this->deleteJson("/api/v1/employees/$emp/documents/$id")->assertNoContent();
        $this->fileOf($emp, $id)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    public function test_a_missing_file_on_disk_is_404(): void
    {
        $emp = $this->employee();
        $id = $this->upload($emp)->assertCreated()->json('data.id');
        Storage::disk('local')->delete(EmployeeDocument::findOrFail($id)->path());
        $this->fileOf($emp, $id)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    public function test_a_manager_sees_the_list_only(): void
    {
        [$manager, $emp] = $this->managed();
        $id = $this->upload($emp, ['note' => 'سري'])->assertCreated()->json('data.id');
        $this->actingAs($manager, 'api');
        // without employee_document.view, a site manager reads nothing about documents (owner, 2026-11-19)
        $res = $this->getJson("/api/v1/employees/$emp")->assertOk()->assertJsonMissingPath('data.documents');
        $this->assertStringNotContainsString('سري', (string) $res->getContent());
        $this->fileOf($emp, $id)->assertStatus(403)->assertJsonPath('error.code', 'permission_denied');
    }

    public function test_an_own_view_grant_reaches_only_the_managers_employees(): void
    {
        Role::where('slug', 'manager')->sole()->permissions()->attach(Permission::where(['resource' => 'employee_document', 'action' => 'view', 'scope' => 'own'])->value('id'));
        [$manager, $emp] = $this->managed();
        $other = $this->employee();
        $mine = $this->upload($emp)->assertCreated()->json('data.id');
        $theirs = $this->upload($other)->assertCreated()->json('data.id');
        $this->actingAs($manager, 'api');
        $this->fileOf($emp, $mine)->assertOk();
        $this->getJson("/api/v1/employees/$emp")->assertOk()->assertJsonPath('data.documents.0.original_name', 'عقد.pdf');
        $this->fileOf($other, $theirs)->assertNotFound();
    }

    private function grant(string ...$triples): void
    {
        foreach ($triples as $t) {
            [$resource, $action, $scope] = explode('.', $t);
            Role::where('slug', 'manager')->sole()->permissions()->attach(Permission::where(compact('resource', 'action', 'scope'))->sole()->id);
        }
    }

    public function test_an_own_view_grant_shows_file_fields_only_in_reach_even_with_employee_view_all(): void
    {
        $this->grant('employee.view.all', 'employee_document.view.own');
        [$manager, $emp] = $this->managed();
        $other = $this->employee();
        $this->upload($emp)->assertCreated();
        $this->upload($other, ['note' => 'سري'])->assertCreated();
        $this->actingAs($manager, 'api');
        $this->getJson("/api/v1/employees/$emp")->assertOk()->assertJsonPath('data.documents.0.original_name', 'عقد.pdf');
        $res = $this->getJson("/api/v1/employees/$other")->assertOk();
        $this->assertSame(['id', 'kind', 'expires_at'], array_keys($res->json('data.documents.0')));
        $this->assertStringNotContainsString('سري', (string) $res->getContent());
    }

    public function test_an_employee_mutation_answers_hr_with_the_file_fields(): void
    {
        $emp = $this->employee();
        $this->upload($emp)->assertCreated();
        $this->patchJson("/api/v1/employees/$emp", ['name_ar' => 'اسم'])->assertOk()->assertJsonPath('data.documents.0.original_name', 'عقد.pdf');
    }

    public function test_the_old_per_kind_route_is_gone(): void
    {
        $emp = $this->employee();
        $this->upload($emp)->assertCreated();   // kind work_stub
        $this->putJson("/api/v1/employees/$emp/documents/work_stub", ['note' => 'x'])->assertNotFound();
        $this->assertNull(EmployeeDocument::where('employee_id', $emp)->value('note'));
    }

    public function test_hr_sees_the_file_fields_sorted_by_kind_then_newest(): void
    {
        $emp = $this->employee();
        $this->upload($emp, ['kind' => 'other'])->assertCreated();
        $this->upload($emp, ['kind' => 'national_id_copy'])->assertCreated();
        $newer = $this->upload($emp, ['kind' => 'national_id_copy'])->assertCreated()->json('data.id');
        $res = $this->getJson("/api/v1/employees/$emp")->assertOk()
            ->assertJsonPath('data.documents.*.kind', ['national_id_copy', 'national_id_copy', 'other'])
            ->assertJsonPath('data.documents.0.id', $newer);
        $this->assertSame(['id', 'kind', 'expires_at', 'original_name', 'mime', 'size_bytes', 'note', 'created_at', 'uploader_name'], array_keys($res->json('data.documents.0')));
        $this->assertStringNotContainsString('received_at', (string) $res->getContent());
    }
}
