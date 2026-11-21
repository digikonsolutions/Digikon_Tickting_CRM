<?php

namespace Tests\Feature;

use App\Models\AgencyRequest;
use App\Models\Attachment;
use App\Models\User;
use App\Notifications\AgencyRequestNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsAgencyFixtures;
use Tests\TestCase;

/** Requests (more hours, an employee, ...) are stored with notes and files, and stay visible to the right people. */
class AgencyRequestsTest extends TestCase
{
    use BuildsAgencyFixtures;
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->setUpWeek();
    }

    private function png(string $name = 'brief.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    public function test_an_owner_requests_an_employee_with_notes_and_a_file_and_admins_are_told(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $agency = $this->makeAgency('Northwind');
        $owner = $this->makeOwner($agency);

        $response = $this->actingAs($owner)->post('/api/agency/requests', ['type' => 'employee', 'message' => 'We need a GoHighLevel builder from January.', 'files' => [$this->png()]], ['Accept' => 'application/json'])
            ->assertCreated();

        $response->assertJsonPath('request.type', 'employee')->assertJsonPath('request.message', 'We need a GoHighLevel builder from January.')->assertJsonCount(1, 'request.attachments');
        $row = AgencyRequest::firstOrFail();
        $this->assertSame($agency->id, $row->agency_id);
        $this->assertSame(1, $row->attachments()->count());
        Storage::disk('local')->assertExists($row->attachments()->first()->path);
        Notification::assertSentTo($admin, AgencyRequestNotice::class, fn ($n) => str_contains($n->payload['body'], 'an additional employee') && str_contains($n->payload['body'], 'GoHighLevel'));

        // It is still there afterwards, with the file.
        $this->getJson('/api/agency/requests?type=employee')->assertOk()->assertJsonPath('requests.0.message', 'We need a GoHighLevel builder from January.')->assertJsonCount(1, 'requests.0.attachments');
        $this->getJson('/api/agency/employees/summary')->assertOk()->assertJsonCount(1, 'requests')->assertJsonPath('seats.used', 0);
    }

    public function test_the_old_json_requests_still_work_and_unknown_types_are_rejected(): void
    {
        Notification::fake();
        $owner = $this->makeOwner($this->makeAgency('Northwind'));

        $this->actingAs($owner)->postJson('/api/agency/requests', ['type' => 'more_hours', 'message' => 'Busy month.'])->assertCreated()->assertJsonPath('notified', 0);
        $this->postJson('/api/agency/requests', ['type' => 'pizza'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->postJson('/api/agency/requests', ['type' => 'renewal', 'message' => str_repeat('x', 1001)])->assertJsonValidationErrors('message');
        $this->assertSame(1, AgencyRequest::count());
    }

    public function test_files_are_checked_and_a_bad_one_stores_nothing(): void
    {
        $owner = $this->makeOwner($this->makeAgency('Northwind'));
        $fake = UploadedFile::fake()->createWithContent('page.png', '<html><script>alert(1)</script></html>');
        $exe = UploadedFile::fake()->createWithContent('run.exe', 'MZ');

        $this->actingAs($owner)->post('/api/agency/requests', ['type' => 'more_hours', 'files' => [$this->png(), $fake]], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('files.1');
        $this->post('/api/agency/requests', ['type' => 'more_hours', 'files' => [$exe]], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('files.0');
        $this->assertSame(0, AgencyRequest::count());
        $this->assertSame(0, Attachment::count());
    }

    public function test_clients_cannot_request_employees_and_only_see_their_own_requests(): void
    {
        Notification::fake();
        $agency = $this->makeAgency('Northwind');
        $owner = $this->makeOwner($agency);
        $ann = $this->makeClient($agency, 'Ann');
        $bob = $this->makeClient($agency, 'Bob');

        $this->actingAs($ann)->postJson('/api/agency/requests', ['type' => 'employee'])->assertForbidden();
        $this->postJson('/api/agency/requests', ['type' => 'more_hours', 'message' => 'Ann asks'])->assertCreated();
        $this->actingAs($bob)->postJson('/api/agency/requests', ['type' => 'upgrade', 'message' => 'Bob asks'])->assertCreated();

        $this->assertSame(['Bob asks'], array_column($this->getJson('/api/agency/requests')->json('requests'), 'message'));
        $this->assertCount(2, $this->actingAs($owner)->getJson('/api/agency/requests')->json('requests'));
    }

    public function test_downloads_are_tenant_scoped(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $agency = $this->makeAgency('Northwind');
        $owner = $this->makeOwner($agency);
        $ann = $this->makeClient($agency, 'Ann');
        $bob = $this->makeClient($agency, 'Bob');
        $rival = $this->makeOwner($this->makeAgency('Rival'), 'Rival Owner');

        $this->actingAs($ann)->post('/api/agency/requests', ['type' => 'more_hours', 'files' => [$this->png()]], ['Accept' => 'application/json'])->assertCreated();
        $url = $this->getJson('/api/agency/requests')->json('requests.0.attachments.0.download_url');

        $this->actingAs($ann)->get($url)->assertOk();
        $this->actingAs($owner)->get($url)->assertOk();
        $this->actingAs($admin)->get($url)->assertOk();
        $this->actingAs($bob)->get($url)->assertNotFound();            // a colleague's request
        $this->actingAs($rival)->get($url)->assertNotFound();          // another agency
        auth()->forgetGuards();
        $this->getJson($url)->assertUnauthorized();
    }
}
