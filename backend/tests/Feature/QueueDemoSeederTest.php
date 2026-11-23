<?php

namespace Tests\Feature;

use App\Models\EmployeeAssignment;
use App\Models\RushRequest;
use App\Models\User;
use App\Models\WorkItem;
use Database\Seeders\QueueDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QueueDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private function local(): void
    {
        $this->app['env'] = 'local';
    }

    public function test_it_does_nothing_without_a_demo_password(): void
    {
        $this->local();
        putenv('DEMO_PASSWORD');
        unset($_ENV['DEMO_PASSWORD'], $_SERVER['DEMO_PASSWORD']);

        $this->seed(QueueDemoSeeder::class);

        $this->assertSame(0, WorkItem::query()->count());
        $this->assertSame(0, User::query()->count());
    }

    public function test_it_builds_busy_queues_and_a_pending_request_and_is_safe_to_run_twice(): void
    {
        $this->local();
        // A throwaway value for this test process only; it is never stored anywhere.
        putenv('DEMO_PASSWORD='.bin2hex(random_bytes(12)).'Aa1!');
        $_ENV['DEMO_PASSWORD'] = $_SERVER['DEMO_PASSWORD'] = getenv('DEMO_PASSWORD');

        $this->seed(QueueDemoSeeder::class);
        $this->seed(QueueDemoSeeder::class);

        $this->assertSame(4, EmployeeAssignment::query()->where('status', 'active')->count());
        $this->assertSame(7, WorkItem::query()->count());
        $ava = User::query()->where('email', 'queue.ava@example.test')->firstOrFail();
        $this->assertSame([1, 2, 3], WorkItem::query()->where('employee_id', $ava->id)->orderBy('queue_position')->pluck('queue_position')->all());
        $this->assertSame('Homepage redesign', WorkItem::query()->where('employee_id', $ava->id)->orderBy('queue_position')->value('title'));
        $this->assertSame(1, RushRequest::query()->where('status', 'pending')->count());
        $this->assertSame(1, User::query()->where('email', 'queue.pool@example.test')->count());

        putenv('DEMO_PASSWORD');
        unset($_ENV['DEMO_PASSWORD'], $_SERVER['DEMO_PASSWORD']);
    }
}
