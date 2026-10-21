<?php

namespace Tests\Feature;

use App\Modules\Attendance\Application\SendMissingDaysAlert;
use App\Modules\Identity\Infrastructure\Eloquent\Permission;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Notifications\Application\Notifier;
use App\Modules\Notifications\Infrastructure\Eloquent\Notification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\AttendanceFixtures;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use AttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cairo('2026-10-20 21:00');
    }

    public function test_the_alert_creates_one_notification_per_permission_holder_per_date(): void
    {
        $this->employee('engineer', '2026-09-26');   // 09-26 → 10-20 is 25 days, less 3 Fridays: 22 missing
        $hr1 = $this->makeUser('hr');
        $hr2 = $this->makeUser('hr');
        $this->makeUser('ceo');
        $this->makeUser('manager');
        $this->makeUser('hr', ['is_active' => false]);
        $clerk = Role::create(['slug' => 'clerk', 'name' => 'Clerk', 'name_ar' => 'كاتب']);
        $clerk->permissions()->attach(Permission::where(['resource' => 'attendance', 'action' => 'edit', 'scope' => 'all'])->value('id'));
        $c = $this->makeUser('clerk');

        $this->artisan('attendance:alert-missing', ['date' => '2026-10-20'])->assertSuccessful();
        $this->artisan('attendance:alert-missing', ['date' => '2026-10-20'])->assertSuccessful();   // a re-run is a no-op

        $this->assertEqualsCanonicalizing([$hr1->id, $hr2->id, $c->id], Notification::pluck('user_id')->all());
        // assertEquals, not assertSame: jsonb stores object keys in its own order.
        $this->assertEquals(['date' => '2026-10-20', 'groups' => [['group' => 'engineer', 'period_start' => '2026-09-26', 'period_end' => '2026-10-25', 'missing' => 22]]], Notification::first()?->data);
        $this->assertSame(22, Notification::first()?->data['groups'][0]['missing']);   // an int: the bell pluralizes it
        $this->assertSame(['attendance_missing_days'], Notification::distinct()->pluck('type')->all());
    }

    public function test_days_before_a_mid_period_hire_are_not_counted(): void
    {
        $this->employee('engineer', '2026-10-10');   // 10-10 → 10-20 is 11 days, less Friday 10-16: 10 missing
        $this->makeUser('hr');

        $this->artisan('attendance:alert-missing', ['date' => '2026-10-20'])->assertSuccessful();

        $this->assertSame(10, Notification::first()?->data['groups'][0]['missing']);
    }

    public function test_a_job_that_fails_its_last_try_is_recorded_in_failed_jobs(): void
    {
        $payload = json_encode(['uuid' => '0192f000-0000-7000-8000-000000000001', 'displayName' => SendMissingDaysAlert::class], JSON_THROW_ON_ERROR);
        app('queue.failer')->log('redis', 'maintenance', $payload, new RuntimeException('down'));

        $this->assertSame([SendMissingDaysAlert::class], array_map(fn ($j) => json_decode($j->payload)->displayName, app('queue.failer')->all()));
    }

    public function test_no_notification_when_nothing_is_missing(): void
    {
        $this->makeUser('hr');
        $this->artisan('attendance:alert-missing', ['date' => '2026-10-20'])->assertSuccessful();
        $this->assertSame(0, Notification::count());
    }

    public function test_a_bad_date_fails(): void
    {
        $this->artisan('attendance:alert-missing', ['date' => '20-10-2026'])->assertExitCode(1);
    }

    public function test_the_job_is_queued_on_maintenance_with_retries(): void
    {
        $this->cairo('2026-10-20 00:30');   // still 10-19 in UTC: the default date must be Cairo's
        Queue::fake();
        $this->artisan('attendance:alert-missing')->assertSuccessful();
        Queue::assertPushedOn('maintenance', SendMissingDaysAlert::class, fn ($j) => $j->date === '2026-10-20' && $j->tries === 3 && $j->backoff() === [60, 300]);
    }

    public function test_the_schedule_runs_daily_at_21_cairo(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'attendance:alert-missing'));
        $this->assertSame(['0 21 * * *', 'Africa/Cairo'], [$event?->expression, $event?->timezone]);
    }

    public function test_a_failed_run_is_logged(): void
    {
        Log::spy();
        (new SendMissingDaysAlert('2026-10-20'))->failed(new RuntimeException('down'));
        Log::shouldHaveReceived('error')->with('attendance.missing_days_alert_failed', ['date' => '2026-10-20', 'error' => 'down']);
    }

    public function test_notify_once_is_true_only_the_first_time(): void
    {
        $id = $this->makeUser('hr')->id;
        $notifier = app(Notifier::class);
        $this->assertTrue($notifier->notifyOnce($id, 'attendance_missing_days', '2026-10-20', ['n' => 1]));
        $this->assertFalse($notifier->notifyOnce($id, 'attendance_missing_days', '2026-10-20', ['n' => 2]));
        $this->assertTrue($notifier->notifyOnce($id, 'attendance_missing_days', '2026-10-21', ['n' => 3]));
        $this->assertSame(2, Notification::count());
    }

    public function test_users_read_and_mark_only_their_own_notifications(): void
    {
        $a = $this->makeUser('manager');
        $b = $this->makeUser('hr');
        $notifier = app(Notifier::class);
        $notifier->notifyOnce($a->id, 'attendance_missing_days', '2026-10-19', ['date' => '2026-10-19']);
        $notifier->notifyOnce($a->id, 'attendance_missing_days', '2026-10-20', ['date' => '2026-10-20']);
        $notifier->notifyOnce($b->id, 'attendance_missing_days', '2026-10-20', ['date' => '2026-10-20']);
        $mine = Notification::where('user_id', $a->id)->where('date', '2026-10-20')->value('id');
        $theirs = Notification::where('user_id', $b->id)->value('id');

        $this->actingAs($a, 'api');
        $list = $this->getJson('/api/v1/notifications?per_page=10')->assertOk();
        $this->assertSame(['2026-10-20', '2026-10-19'], array_column($list->json('data'), 'date'));
        $this->assertSame(['id', 'type', 'date', 'data', 'read_at'], array_keys($list->json('data.0')));
        $this->assertSame(2, $list->json('meta.pagination.total'));

        $this->patchJson("/api/v1/notifications/{$theirs}/read")->assertNotFound();
        $this->assertNull(Notification::find($theirs)?->read_at);

        $first = $this->patchJson("/api/v1/notifications/{$mine}/read")->assertOk()->assertJsonPath('data.id', $mine)->json('data.read_at');
        $this->assertNotNull($first);
        $this->travel(1)->hours();
        $this->patchJson("/api/v1/notifications/{$mine}/read")->assertOk()->assertJsonPath('data.read_at', $first);
    }
}
