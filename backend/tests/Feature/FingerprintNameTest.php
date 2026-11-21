<?php

namespace Tests\Feature;

use App\Modules\Employees\Application\EmployeeRoster;
use App\Modules\Employees\Application\EmployeeRules;
use App\Modules\Employees\Infrastructure\Eloquent\Employee;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** FP-2 (#66): the fingerprint-name lookup and its uniqueness. Names are fake placeholders. */
class FingerprintNameTest extends TestCase
{
    use RefreshDatabase;

    private const NBSP = "Sara\u{00A0}Nabil";

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn('hr');
    }

    public function test_case_and_spaces_are_ignored(): void
    {
        $id = $this->make('NadimFares');

        $found = $this->lookup(['nadimfares', ' NADIMFARES ', 'Nadim Fares']);

        $this->assertSame(['nadimfares', ' NADIMFARES ', 'Nadim Fares'], array_keys($found));
        foreach ($found as $row) {
            $this->assertSame($id, $row['id']);
        }
    }

    public function test_an_unknown_name_is_left_out(): void
    {
        $this->make('NadimFares');

        $this->assertSame(['nadimfares'], array_keys($this->lookup(['nadimfares', 'Nobody'])));
    }

    public function test_inner_spaces_are_ignored(): void
    {
        $id = $this->make('Sara  Nabil');

        $found = $this->lookup(['saranabil', 'sara nabil']);

        $this->assertSame($id, $found['saranabil']['id']);
        $this->assertSame($id, $found['sara nabil']['id']);
    }

    public function test_a_no_break_space_is_part_of_the_name(): void
    {
        $this->make('Sara Nabil');

        $this->assertSame([], $this->lookup([self::NBSP]));
    }

    public function test_arabic_letters_survive_the_normalisation(): void
    {
        $id = $this->make('محمد سامي');   // م is the bytes D9 85; PCRE's \v would strip the 85

        $this->assertSame('محمدسامي', EmployeeRules::fingerprintKey('محمد سامي'));
        $this->assertSame($id, $this->lookup(['محمدسامي'])['محمدسامي']['id']);
    }

    public function test_an_archived_employee_matches(): void
    {
        $id = $this->make('NadimFares');
        $this->archive($id);

        $this->assertSame($id, $this->lookup(['nadimfares'])['nadimfares']['id']);
    }

    public function test_a_soft_deleted_employee_never_matches(): void
    {
        $id = $this->make('NadimFares');
        Employee::whereKey($id)->firstOrFail()->delete();

        $this->assertSame([], $this->lookup(['nadimfares']));
    }

    public function test_the_row_is_the_find_row(): void
    {
        $id = $this->make('NadimFares');

        $row = $this->lookup(['nadimfares'])['nadimfares'];

        foreach (['id', 'code', 'types', 'shifts'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
        $this->assertSame(app(EmployeeRoster::class)->find($id), $row);
    }

    public function test_five_names_take_as_many_queries_as_one(): void
    {
        foreach (['Fp One', 'Fp Two', 'Fp Three', 'Fp Four', 'Fp Five'] as $name) {
            $this->make($name);
        }

        $one = $this->queries(['fpone']);
        $five = $this->queries(['fpone', 'fptwo', 'fpthree', 'fpfour', 'fpfive']);

        $this->assertSame(5, $one);   // the employees, then the four eager-loaded relations
        $this->assertSame($one, $five);
    }

    public function test_another_employee_cannot_take_the_name_in_another_case(): void
    {
        $this->make('NadimFares');

        $this->assertTaken('nadimfares');
    }

    public function test_another_employee_cannot_take_the_name_with_a_space(): void
    {
        $this->make('NadimFares');

        $this->assertTaken('Nadim Fares');
    }

    public function test_the_employee_may_change_the_case_of_their_own_name(): void
    {
        $id = $this->make('NadimFares');

        $this->patchJson("/api/v1/employees/$id", ['fingerprint_name' => 'NADIMFARES'])->assertOk()->assertJsonPath('data.fingerprint_name', 'NADIMFARES');
    }

    public function test_a_soft_deleted_employees_name_is_free(): void
    {
        $id = $this->make('NadimFares');
        Employee::whereKey($id)->firstOrFail()->delete();

        $this->make('NadimFares');
    }

    public function test_an_archived_employee_still_holds_the_name(): void
    {
        $this->archive($this->make('NadimFares'));

        $this->assertTaken('NadimFares');
    }

    public function test_a_no_break_space_name_is_accepted_beside_an_ordinary_space_one(): void
    {
        $this->make('Sara Nabil');

        $this->make(self::NBSP);
    }

    public function test_the_database_refuses_a_duplicate_normalised_name(): void
    {
        $this->make('NadimFares');

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('employees')->insert(['id' => (string) Str::uuid7(), 'code' => 999, 'name_ar' => 'موظف', 'gender' => 'male', 'fingerprint_name' => 'nadim fares']);
    }

    /** A new employee with $fingerprintName is refused with the unique message on that field. */
    private function assertTaken(string $fingerprintName): void
    {
        $this->postJson('/api/v1/employees', $this->payload($fingerprintName))->assertStatus(422)
            ->assertJsonPath('error.details.0.field', 'fingerprint_name')
            ->assertJsonPath('error.details.0.message', __('validation.unique', ['attribute' => __('validation.attributes.fingerprint_name')]));
    }

    /** @return array<string, mixed> */
    private function payload(string $fingerprintName): array
    {
        return ['name_ar' => 'موظف', 'gender' => 'male', 'type' => 'office', 'effective_from' => '2026-01-01', 'salary' => '9000', 'fingerprint_name' => $fingerprintName];
    }

    private function make(string $fingerprintName): string
    {
        return $this->postJson('/api/v1/employees', $this->payload($fingerprintName))->assertCreated()->json('data.id');
    }

    /** Terminated before today in Cairo, so archived (CONTEXT.md, Archive). */
    private function archive(string $id): void
    {
        Employee::whereKey($id)->update(['termination_date' => now('Africa/Cairo')->subDays(10)->toDateString()]);
    }

    /**
     * @param  list<string>  $names
     * @return array<string, array<string, mixed>>
     */
    private function lookup(array $names): array
    {
        return app(EmployeeRoster::class)->byFingerprintNames($names);
    }

    /** @param  list<string>  $names */
    private function queries(array $names): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->lookup($names);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }
}
