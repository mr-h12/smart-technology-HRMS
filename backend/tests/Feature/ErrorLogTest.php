<?php

namespace Tests\Feature;

use App\Modules\Audit\Infrastructure\Eloquent\ErrorLogEntry;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class ErrorLogTest extends TestCase
{
    use RefreshDatabase;

    private const NATIONAL_ID = '29801011234567';

    /** By request ID: tests without RefreshDatabase (ErrorEnvelopeTest) leave committed rows behind. */
    private function rowOf(TestResponse $response): ErrorLogEntry
    {
        return ErrorLogEntry::where('request_id', $response->headers->get('X-Request-Id'))->sole();
    }

    /** Rows whose whole text (every column, jsonb included) contains $needle. */
    private function rowsContaining(string $table, string $needle): int
    {
        return DB::table("$table as r")->whereRaw('r::text like ?', ['%'.$needle.'%'])->count();
    }

    private function employee(?string $nationalId = null): string
    {
        return $this->postJson('/api/v1/employees', [
            'name_ar' => 'موظف', 'gender' => 'male', 'type' => 'office', 'effective_from' => '2026-01-01', 'salary' => '9000', 'national_id' => $nationalId,
        ])->assertCreated()->json('data.id');
    }

    /** A savepoint: the failed statement rolls back only itself, not the test's transaction. */
    private function queryException(callable $statement): QueryException
    {
        try {
            DB::transaction($statement);
        } catch (QueryException $e) {
            return $e;
        }
        $this->fail('The statement did not fail.');
    }

    public function test_an_unhandled_exception_is_logged_and_linked(): void
    {
        Route::get('api/v1/_boom', fn () => throw new RuntimeException('boom '.self::NATIONAL_ID))->middleware('api');
        $res = $this->getJson('/api/v1/_boom')->assertStatus(500);
        $requestId = $res->headers->get('X-Request-Id');

        $row = $this->rowOf($res);
        $this->assertSame(RuntimeException::class, $row->class);
        $this->assertSame('boom [masked]', $row->message);
        $this->assertSame(__FILE__, $row->file);
        $this->assertIsInt($row->line);
        $this->assertNull($row->sqlstate);
        $this->assertSame(500, DB::table('request_log')->where('request_id', $requestId)->value('status'));
    }

    public function test_dont_report_exceptions_are_not_logged(): void
    {
        $before = ErrorLogEntry::count();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/nope')->assertNotFound();
        $this->signIn('hr');
        $this->postJson('/api/v1/employees', [])->assertStatus(422);
        $this->postJson('/api/v1/roles', [])->assertForbidden();

        $this->assertSame($before, ErrorLogEntry::count());
    }

    public function test_a_database_error_stores_no_values(): void
    {
        $this->signIn('hr');
        $this->employee(self::NATIONAL_ID);
        $b = $this->employee();
        $before = ErrorLogEntry::pluck('id');

        report($this->queryException(fn () => DB::table('employees')->where('id', $b)->update(['national_id' => self::NATIONAL_ID])));

        $row = ErrorLogEntry::whereNotIn('id', $before)->sole();
        $this->assertSame('23505', $row->sqlstate);
        $this->assertSame(UniqueConstraintViolationException::class, $row->class);
        $this->assertStringContainsString('constraint employees_national_id_unique', $row->message);
        $this->assertStringContainsString('update "employees" set "national_id" = ?', $row->message);
        $this->assertSame(0, $this->rowsContaining('error_log', self::NATIONAL_ID));
        $this->assertSame(0, $this->rowsContaining('request_log', self::NATIONAL_ID));
    }

    public function test_a_constraint_name_is_read_from_the_first_line_only(): void
    {
        // A not-null violation names no constraint on line 1; its DETAIL line quotes the failing row, which holds `constraint "x"`.
        $e = $this->queryException(fn () => DB::table('error_log')->insert([
            'id' => (string) Str::uuid7(), 'class' => null, 'message' => 'constraint "x" 9000.000000', 'trace' => 'x', 'occurred_at' => now(),
        ]));
        $this->assertStringContainsString('constraint "x"', (string) $e->errorInfo[2]);
        $before = ErrorLogEntry::pluck('id');

        report($e);

        $row = ErrorLogEntry::whereNotIn('id', $before)->sole();
        $this->assertSame('23502', $row->sqlstate);
        $this->assertStringStartsWith("SQLSTATE 23502\ninsert into \"error_log\"", $row->message);
    }

    public function test_an_unknown_sqlstate_is_named(): void
    {
        $before = ErrorLogEntry::pluck('id');

        report(new PDOException('driver gone'));

        $row = ErrorLogEntry::whereNotIn('id', $before)->sole();
        $this->assertNull($row->sqlstate);
        $this->assertSame('SQLSTATE unknown', $row->message);
    }

    public function test_the_trace_has_no_arguments(): void
    {
        $helper = fn (string $secret) => throw new RuntimeException('x');
        Route::get('api/v1/_args', fn () => $helper('abc-secret-arg'))->middleware('api');
        $trace = $this->rowOf($this->getJson('/api/v1/_args')->assertStatus(500))->trace;
        $this->assertStringStartsWith('#0 ', $trace);
        $this->assertStringContainsString('Illuminate\\Routing\\CallableDispatcher->dispatch()', $trace);
        $this->assertStringNotContainsString('abc-secret-arg', $trace);
    }

    public function test_query_exception_message_masks_bindings(): void
    {
        // error_log.sqlstate is varchar(5); "value too long" does not echo the value, so only the SQL part could.
        $e = $this->queryException(fn () => DB::table('error_log')->insert([
            'id' => (string) Str::uuid7(), 'class' => 'x', 'message' => 'x', 'trace' => 'x', 'occurred_at' => now(), 'sqlstate' => 'too-long-binding',
        ]));

        $this->assertStringContainsString('value too long', $e->getMessage());
        $this->assertStringNotContainsString('too-long-binding', $e->getMessage());
    }
}
