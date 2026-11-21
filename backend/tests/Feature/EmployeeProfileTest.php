<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Notifications\SetPasswordNotification;
use App\Services\EmployeeProfileService;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class EmployeeProfileTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => Role::Admin]);
    }

    private function pdf(string $name = 'cv.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
    }

    private function png(string $name = 'front.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG));
    }

    /** @return array<string, mixed> */
    private function fullPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ayesha Khan',
            'email' => 'ayesha@example.com',
            'father_name' => 'Imran Khan',
            'date_of_birth' => '1999-04-12',
            'gender' => 'female',
            'cnic' => '34101-1234567-1',
            'phone' => '+92 300 1234567',
            'personal_email' => 'ayesha.personal@example.com',
            'current_address' => '12 Secret Street, Lahore',
            'headline' => 'Full-stack developer',
            'bio' => 'Builds reliable web apps.',
            'department' => 'web',
            'timezone' => 'Asia/Karachi',
            'availability' => 'available',
            'join_date' => '2025-01-10',
            'employment_type' => 'full_time',
            'employment_status' => 'probation',
            'probation_end_date' => '2025-04-10',
            'weekly_capacity_hours' => 36,
            'pay_rate' => 1500.50,
            'bank_name' => 'HBL',
            'bank_account' => 'PK36HABB0000001234567702',
            'internal_notes' => 'Strong interview.',
            'skills' => [
                ['name' => 'Laravel', 'level' => 'expert', 'years_experience' => 4.5],
                ['name' => 'React', 'level' => 'intermediate', 'years_experience' => 2],
            ],
            'educations' => [['degree' => 'BSCS', 'institution' => 'UET', 'start_date' => '2017-09-01', 'end_date' => '2021-06-30']],
            'experiences' => [['title' => 'Developer', 'company' => 'Acme', 'start_date' => '2021-07-01', 'end_date' => '2024-12-31']],
            'certifications' => [['name' => 'AWS CCP', 'issuer' => 'Amazon', 'issued_at' => '2023-02-01']],
        ], $overrides);
    }

    /** Multipart create (documents are real uploads). */
    private function createEmployee(array $payload, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->post('/api/admin/employees', $payload, ['Accept' => 'application/json', ...$headers]);
    }

    private function employeeWithProfile(array $overrides = [], array $userAttrs = []): User
    {
        $employee = User::factory()->create(['role' => Role::Employee, ...$userAttrs]);
        app(EmployeeProfileService::class)->apply($employee, $this->fullPayload($overrides));

        return $employee->fresh();
    }

    public function test_admin_creates_an_employee_with_full_profile_skills_and_documents(): void
    {
        Notification::fake();

        $response = $this->createEmployee($this->fullPayload([
            'documents' => [
                ['file' => $this->pdf('my cv.pdf'), 'type' => 'cv'],
                ['file' => $this->png(), 'type' => 'cnic_front', 'expires_at' => '2030-01-01'],
            ],
        ]))->assertCreated();

        $employee = User::where('email', 'ayesha@example.com')->firstOrFail();
        $this->assertSame(Role::Employee, $employee->role);
        $response->assertJsonPath('employee.id', $employee->id)
            ->assertJsonPath('employee.profile.employee_code', 'EMP-0001')
            ->assertJsonPath('employee.profile.cnic_masked', '34101-*******-1')
            ->assertJsonPath('employee.profile.bank_account_masked', '********************7702')
            ->assertJsonPath('employee.profile.department', 'web')
            ->assertJsonCount(2, 'employee.skills')
            ->assertJsonCount(1, 'employee.educations')
            ->assertJsonCount(2, 'employee.documents');

        // Sensitive values are masked in the response and encrypted in the database.
        $this->assertStringNotContainsString('3410112345671', $response->getContent());
        $this->assertStringNotContainsString('PK36HABB', $response->getContent());
        $raw = DB::table('employee_profiles')->where('user_id', $employee->id)->first();
        $this->assertStringNotContainsString('3410112345671', (string) $raw->cnic);
        $this->assertStringNotContainsString('PK36HABB', (string) $raw->bank_account);
        $this->assertSame('3410112345671', $employee->employeeProfile->cnic);

        $this->assertDatabaseCount('employee_skills', 2);
        $this->assertDatabaseCount('employee_documents', 2);
        foreach (EmployeeDocument::all() as $document) {
            Storage::disk('local')->assertExists($document->path);
        }
        Notification::assertSentTo($employee, SetPasswordNotification::class);
    }

    public function test_only_name_and_email_are_required_so_a_draft_can_be_saved(): void
    {
        Notification::fake();

        $this->createEmployee(['name' => 'Draft Person', 'email' => 'draft@example.com'])
            ->assertCreated()
            ->assertJsonPath('employee.profile.employee_code', 'EMP-0001')
            ->assertJsonPath('employee.profile.availability', 'available')
            ->assertJsonPath('employee.profile.has_cnic', false)
            ->assertJsonPath('employee.completeness.percent', 0)
            ->assertJsonCount(0, 'employee.skills');

        $this->createEmployee(['email' => 'nameless@example.com'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->createEmployee(['name' => 'No Email'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->createEmployee(['name' => 'Role Injector', 'email' => 'inj@example.com', 'role' => 'admin'])->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    public function test_failed_invitation_rolls_back_the_profile_lists_and_stored_files(): void
    {
        $this->mock(Dispatcher::class)
            ->shouldReceive('send')->once()->andThrow(new RuntimeException('Invitation delivery failed.'));

        $this->createEmployee($this->fullPayload([
            'email' => 'rollback@example.com',
            'documents' => [['file' => $this->pdf(), 'type' => 'cv']],
        ]))->assertInternalServerError();

        $this->assertDatabaseMissing('users', ['email' => 'rollback@example.com']);
        foreach (['employee_profiles', 'employee_skills', 'employee_educations', 'employee_experiences', 'employee_certifications', 'employee_documents'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame([], Storage::disk('local')->allFiles('employees'));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'rollback@example.com']);
    }

    public function test_non_admins_get_403_and_guests_get_401_on_every_admin_employee_endpoint(): void
    {
        $employee = $this->employeeWithProfile();
        $agency = Agency::create(['name' => 'Northwind']);
        $roles = [
            User::factory()->create(['role' => Role::Employee]),
            User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]),
            User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]),
            User::factory()->create(['role' => Role::AgencyMember, 'agency_id' => $agency->id]),
        ];
        $id = $employee->id;
        $calls = [
            ['postJson', '/api/admin/employees'],
            ['getJson', "/api/admin/employees/{$id}"],
            ['patchJson', "/api/admin/employees/{$id}"],
            ['postJson', "/api/admin/employees/{$id}/reveal-cnic"],
            ['postJson', "/api/admin/employees/{$id}/documents"],
            ['getJson', "/api/admin/employees/{$id}/documents/1"],
            ['patchJson', "/api/admin/employees/{$id}/documents/1/verify"],
            ['deleteJson', "/api/admin/employees/{$id}/documents/1"],
            ['postJson', "/api/admin/employees/{$id}/photo"],
        ];

        foreach ($calls as [$method, $url]) {
            $this->{$method}($url)->assertUnauthorized();
        }
        foreach ($roles as $user) {
            foreach ($calls as [$method, $url]) {
                $this->actingAs($user)->{$method}($url)->assertForbidden();
            }
        }
    }

    public function test_agency_profile_returns_only_the_public_whitelist(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $employee = $this->employeeWithProfile();
        $this->actingAs($this->admin)->post("/api/admin/employees/{$employee->id}/documents", ['file' => $this->pdf(), 'type' => 'cv'], ['Accept' => 'application/json'])->assertCreated();

        foreach ([Role::AgencyOwner, Role::AgencyClient] as $role) {
            $viewer = User::factory()->create(['role' => $role, 'agency_id' => $agency->id]);
            $response = $this->actingAs($viewer)->getJson("/api/agency/employees/{$employee->id}/profile")->assertOk();
            $body = $response->json('employee');

            $this->assertEqualsCanonicalizing([
                'id', 'name', 'photo_url', 'headline', 'bio', 'department', 'skills', 'educations', 'experiences',
                'certifications', 'english_level', 'timezone', 'availability', 'preferred_hours', 'portfolio_url',
                'github_url', 'linkedin_url', 'weekly_capacity_hours',
            ], array_keys($body));
            $response->assertJsonPath('employee.headline', 'Full-stack developer')->assertJsonCount(2, 'employee.skills');

            foreach (['cnic', 'cnic_masked', 'phone', 'whatsapp', 'email', 'personal_email', 'current_address', 'permanent_address', 'bank_account', 'bank_name', 'pay_rate', 'internal_notes', 'documents', 'checklist', 'father_name', 'date_of_birth'] as $key) {
                $this->assertArrayNotHasKey($key, $body);
            }
            // And none of the private VALUES appear anywhere in the payload.
            foreach (['3410112345671', '34101-', '+92 300 1234567', 'ayesha@example.com', 'ayesha.personal@example.com', 'Secret Street', 'PK36HABB', '1500.5', 'Strong interview', 'Imran Khan', 'cv.pdf'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
            $response->assertJsonMissing(['cnic' => '3410112345671'])->assertJsonMissing(['phone' => '+92 300 1234567']);
        }
    }

    public function test_agency_users_can_only_view_active_employees_they_could_hold(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $otherAgency = Agency::create(['name' => 'Other']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $shared = $this->employeeWithProfile();
        $own = User::factory()->create(['role' => Role::Employee, 'agency_id' => $agency->id]);
        $inactive = User::factory()->create(['role' => Role::Employee, 'is_active' => false]);
        $foreign = User::factory()->create(['role' => Role::Employee, 'agency_id' => $otherAgency->id]);
        $client = User::factory()->create(['role' => Role::AgencyClient, 'agency_id' => $agency->id]);

        $this->actingAs($owner);
        $this->getJson("/api/agency/employees/{$shared->id}/profile")->assertOk();
        $this->getJson("/api/agency/employees/{$own->id}/profile")->assertOk()->assertJsonPath('employee.availability', 'available');
        $this->getJson("/api/agency/employees/{$inactive->id}/profile")->assertNotFound();
        $this->getJson("/api/agency/employees/{$foreign->id}/profile")->assertNotFound();
        $this->getJson("/api/agency/employees/{$this->admin->id}/profile")->assertNotFound();
        $this->getJson("/api/agency/employees/{$client->id}/profile")->assertNotFound();
        $this->getJson("/api/agency/employees/{$owner->id}/profile")->assertNotFound();

        // Employees, members and guests cannot use the agency endpoint at all.
        $this->actingAs($shared)->getJson("/api/agency/employees/{$own->id}/profile")->assertForbidden();
        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/agency/employees/{$shared->id}/profile")->assertUnauthorized();
    }

    public function test_available_employees_carry_the_public_summary_and_nothing_private(): void
    {
        $agency = Agency::create(['name' => 'Northwind']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $employee = $this->employeeWithProfile();

        $response = $this->actingAs($owner)->getJson('/api/agency/employees/available')->assertOk();
        $card = collect($response->json('employees'))->firstWhere('id', $employee->id);

        $this->assertSame('Full-stack developer', $card['headline']);
        $this->assertSame('available', $card['availability']);
        $this->assertSame('web', $card['department']);
        $this->assertSame(['Laravel', 'React'], $card['top_skills']);
        $this->assertArrayHasKey('photo_url', $card);
        foreach (['3410112345671', '+92 300 1234567', 'Secret Street', 'PK36HABB', 'Strong interview', '1500.5'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_cnic_is_validated_normalised_unique_and_masked_everywhere(): void
    {
        Notification::fake();
        $this->createEmployee(['name' => 'Bad Cnic', 'email' => 'bad@example.com', 'cnic' => '123'])
            ->assertUnprocessable()->assertJsonValidationErrors('cnic');
        $this->createEmployee(['name' => 'Bad Cnic', 'email' => 'bad@example.com', 'cnic' => '1234-5678'])
            ->assertUnprocessable()->assertJsonValidationErrors('cnic');

        // Dashes, spaces and no separators all normalise to the same 13 digits.
        $first = $this->createEmployee(['name' => 'First', 'email' => 'first@example.com', 'cnic' => '12345 1234567 1'])->assertCreated();
        $this->assertSame('1234512345671', User::where('email', 'first@example.com')->first()->employeeProfile->cnic);
        $first->assertJsonPath('employee.profile.cnic_masked', '12345-*******-1');

        $this->createEmployee(['name' => 'Second', 'email' => 'second@example.com', 'cnic' => '12345-1234567-1'])
            ->assertUnprocessable()->assertJsonValidationErrors('cnic');
        $this->createEmployee(['name' => 'Third', 'email' => 'third@example.com', 'cnic' => '1234512345671'])
            ->assertUnprocessable()->assertJsonValidationErrors('cnic');

        // The owner of a CNIC can save it again on their own profile; someone else cannot take it.
        $firstId = $first->json('employee.id');
        $this->actingAs($this->admin)->patchJson("/api/admin/employees/{$firstId}", ['cnic' => '12345-1234567-1', 'city' => 'Lahore'])->assertOk();
        $other = $this->employeeWithProfile(['cnic' => '54321-7654321-9', 'email' => 'other@example.com']);
        $this->patchJson("/api/admin/employees/{$other->id}", ['cnic' => '12345-1234567-1'])->assertUnprocessable()->assertJsonValidationErrors('cnic');

        // Detail and list responses are masked.
        $this->getJson("/api/admin/employees/{$firstId}")->assertOk()->assertJsonPath('employee.profile.cnic_masked', '12345-*******-1');
        $list = $this->getJson('/api/admin/users?role=employee')->assertOk();
        $this->assertStringNotContainsString('1234512345671', $list->getContent());
        $this->assertStringNotContainsString('12345-1234567-1', $list->getContent());
    }

    public function test_reveal_cnic_returns_the_full_value_and_writes_an_audit_log(): void
    {
        $employee = $this->employeeWithProfile();
        $blank = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($this->admin)->postJson("/api/admin/employees/{$employee->id}/reveal-cnic")
            ->assertOk()->assertJsonPath('cnic', '34101-1234567-1')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->admin->id,
            'action' => 'revealed_cnic',
            'subject_type' => User::class,
            'subject_id' => $employee->id,
        ]);
        $this->assertNotNull(AuditLog::where('action', 'revealed_cnic')->first()->ip);

        $this->postJson("/api/admin/employees/{$blank->id}/reveal-cnic")->assertNotFound();
        $this->assertSame(1, AuditLog::where('action', 'revealed_cnic')->count());

        // Viewing the (masked) full profile is audited too.
        $this->getJson("/api/admin/employees/{$employee->id}")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'viewed_sensitive', 'subject_id' => $employee->id]);
    }

    public function test_reveal_cnic_is_rate_limited(): void
    {
        $employee = $this->employeeWithProfile();
        $this->actingAs($this->admin);

        foreach (range(1, 10) as $ignored) {
            $this->postJson("/api/admin/employees/{$employee->id}/reveal-cnic")->assertOk();
        }
        $this->postJson("/api/admin/employees/{$employee->id}/reveal-cnic")->assertStatus(429);
    }

    public function test_document_upload_rejects_wrong_types_fake_content_and_oversize_files(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $url = "/api/admin/employees/{$employee->id}/documents";
        $post = fn (UploadedFile $file, string $type = 'cv') => $this->actingAs($this->admin)->post($url, ['file' => $file, 'type' => $type], ['Accept' => 'application/json']);

        // PNG bytes named .pdf, HTML named .png, SVG, text and Word files are all refused.
        $post(UploadedFile::fake()->createWithContent('fake.pdf', base64_decode(self::PNG)))->assertUnprocessable()->assertJsonValidationErrors('file');
        $post(UploadedFile::fake()->createWithContent('page.png', '<html><script>alert(1)</script></html>'))->assertUnprocessable()->assertJsonValidationErrors('file');
        $post(UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'))->assertUnprocessable();
        $post(UploadedFile::fake()->createWithContent('notes.txt', 'hello'))->assertUnprocessable();
        $post(UploadedFile::fake()->createWithContent('cv.docx', 'PK'))->assertUnprocessable();
        $post(UploadedFile::fake()->createWithContent('empty.pdf', ''))->assertUnprocessable();

        // 6 MB is over the 5 MB limit.
        $big = $post(UploadedFile::fake()->create('big.pdf', 6 * 1024, 'application/pdf'))->assertUnprocessable();
        $this->assertStringContainsString('larger than 5 MB', $big->json('errors.file.0'));

        // Unknown document type.
        $post($this->pdf(), 'passport')->assertUnprocessable()->assertJsonValidationErrors('type');

        $this->assertDatabaseCount('employee_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());

        // Valid PDF, JPG-named PNG is rejected (content mismatch) but a real PNG passes.
        $post($this->pdf())->assertCreated();
        $post($this->png('scan.png'), 'cnic_back')->assertCreated();
        $post(UploadedFile::fake()->createWithContent('scan.jpg', base64_decode(self::PNG)), 'degree')->assertUnprocessable();
    }

    public function test_documents_are_stored_privately_under_random_names_and_paths_are_never_exposed(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);

        $response = $this->actingAs($this->admin)->post(
            "/api/admin/employees/{$employee->id}/documents",
            ['file' => $this->pdf('Ayesha Contract FINAL.pdf'), 'type' => 'contract', 'expires_at' => now()->addDays(10)->toDateString()],
            ['Accept' => 'application/json']
        )->assertCreated()
            ->assertJsonPath('document.name', 'Ayesha Contract FINAL.pdf')
            ->assertJsonPath('document.type', 'contract')
            ->assertJsonPath('document.expiring_soon', true)
            ->assertJsonPath('document.expired', false)
            ->assertJsonPath('document.verified', false);

        $document = EmployeeDocument::firstOrFail();
        $this->assertMatchesRegularExpression('#^employees/'.$employee->id.'/[0-9a-f-]{36}\.pdf$#', $document->path);
        Storage::disk('local')->assertExists($document->path);
        Storage::disk('public')->assertMissing($document->path);
        $this->assertStringNotContainsString($document->path, $response->getContent());
        $this->assertStringNotContainsString('employees/', $response->getContent());
        $this->assertStringNotContainsString('Ayesha', $document->path);

        // The detail endpoint never lists a path either.
        $detail = $this->getJson("/api/admin/employees/{$employee->id}")->assertOk();
        $this->assertStringNotContainsString($document->path, $detail->getContent());
        $this->assertStringNotContainsString('"path"', $detail->getContent());
        $this->assertStringNotContainsString('"disk"', $detail->getContent());
    }

    public function test_expired_documents_are_flagged(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $this->actingAs($this->admin)->post("/api/admin/employees/{$employee->id}/documents", ['file' => $this->pdf(), 'type' => 'nda', 'expires_at' => now()->subDay()->toDateString()], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('document.expired', true)->assertJsonPath('document.expiring_soon', false);
    }

    public function test_only_admins_can_download_documents_and_each_download_is_audited(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $other = User::factory()->create(['role' => Role::Employee]);
        $agency = Agency::create(['name' => 'Northwind']);
        $owner = User::factory()->create(['role' => Role::AgencyOwner, 'agency_id' => $agency->id]);
        $this->actingAs($this->admin)->post("/api/admin/employees/{$employee->id}/documents", ['file' => $this->pdf(), 'type' => 'cv'], ['Accept' => 'application/json'])->assertCreated();
        $document = EmployeeDocument::firstOrFail();
        $url = "/api/admin/employees/{$employee->id}/documents/{$document->id}";

        // The employee themself, another employee and an agency owner are all refused.
        foreach ([$employee, $other, $owner] as $user) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
        $this->assertSame(0, AuditLog::where('action', 'downloaded_document')->count());

        $download = $this->actingAs($this->admin)->get($url)->assertOk();
        $this->assertStringContainsString('%PDF-1.4', $download->streamedContent());
        $download->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        $download->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertDatabaseHas('audit_logs', ['action' => 'downloaded_document', 'subject_id' => $document->id, 'user_id' => $this->admin->id]);

        // A document id that belongs to a different employee is a 404 under this employee's URL.
        $this->get("/api/admin/employees/{$other->id}/documents/{$document->id}")->assertNotFound();
    }

    public function test_admin_can_verify_unverify_and_remove_a_document(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $this->actingAs($this->admin)->post("/api/admin/employees/{$employee->id}/documents", ['file' => $this->pdf(), 'type' => 'cv'], ['Accept' => 'application/json'])->assertCreated();
        $document = EmployeeDocument::firstOrFail();
        $base = "/api/admin/employees/{$employee->id}/documents/{$document->id}";

        $this->patchJson("{$base}/verify", ['verified' => true])->assertOk()
            ->assertJsonPath('document.verified', true)->assertJsonPath('document.verified_by', $this->admin->name);
        $this->assertSame($this->admin->id, $document->fresh()->verified_by);
        $this->patchJson("{$base}/verify", ['verified' => false])->assertOk()->assertJsonPath('document.verified', false);
        $this->patchJson("{$base}/verify", [])->assertUnprocessable();

        $path = $document->path;
        $this->deleteJson($base)->assertOk();
        $this->assertDatabaseCount('employee_documents', 0);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_deleting_a_user_removes_their_documents_files_and_profile_rows(): void
    {
        $employee = $this->employeeWithProfile();
        $keep = User::factory()->create(['role' => Role::Employee]);
        foreach ([$employee, $keep] as $user) {
            $this->actingAs($this->admin)->post("/api/admin/employees/{$user->id}/documents", ['file' => $this->pdf(), 'type' => 'cv'], ['Accept' => 'application/json'])->assertCreated();
        }
        $paths = EmployeeDocument::where('user_id', $employee->id)->pluck('path');
        $keptPath = EmployeeDocument::where('user_id', $keep->id)->value('path');
        $this->assertCount(1, $paths);

        $this->deleteJson("/api/admin/users/{$employee->id}")->assertOk();

        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
        $this->assertSame([], Storage::disk('local')->directories('employees/'.$employee->id));
        $this->assertDatabaseMissing('employee_documents', ['user_id' => $employee->id]);
        $this->assertDatabaseMissing('employee_profiles', ['user_id' => $employee->id]);
        $this->assertDatabaseMissing('employee_skills', ['user_id' => $employee->id]);
        // Other employees' files are untouched.
        Storage::disk('local')->assertExists($keptPath);
    }

    public function test_update_changes_only_the_sent_fields_and_replaces_lists(): void
    {
        $employee = $this->employeeWithProfile();
        $this->actingAs($this->admin)->patchJson("/api/admin/employees/{$employee->id}", [
            'headline' => 'Senior developer',
            'skills' => [['name' => 'Vue', 'level' => 'beginner']],
        ])->assertOk()
            ->assertJsonPath('employee.profile.headline', 'Senior developer')
            ->assertJsonPath('employee.profile.phone', '+92 300 1234567')   // untouched
            ->assertJsonPath('employee.profile.cnic_masked', '34101-*******-1')
            ->assertJsonCount(1, 'employee.skills')
            ->assertJsonCount(1, 'employee.educations');                     // untouched

        // Null clears a field; an explicit empty list clears a list.
        $this->patchJson("/api/admin/employees/{$employee->id}", ['phone' => null, 'educations' => []])->assertOk()
            ->assertJsonPath('employee.profile.phone', null)->assertJsonCount(0, 'employee.educations');

        // The login email, role and active flag are not editable here.
        $this->patchJson("/api/admin/employees/{$employee->id}", ['email' => 'x@example.com'])->assertUnprocessable();
        $this->patchJson("/api/admin/employees/{$employee->id}", ['is_active' => false])->assertUnprocessable();

        // Admins and agency users are not employees: 404.
        $this->getJson("/api/admin/employees/{$this->admin->id}")->assertNotFound();
    }

    public function test_profile_validation_rules(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);
        $url = "/api/admin/employees/{$employee->id}";
        $this->actingAs($this->admin);

        $this->patchJson($url, ['phone' => 'abc'])->assertJsonValidationErrors('phone');
        $this->patchJson($url, ['department' => 'sales'])->assertJsonValidationErrors('department');
        $this->patchJson($url, ['availability' => 'later'])->assertJsonValidationErrors('availability');
        $this->patchJson($url, ['employment_type' => 'intern'])->assertJsonValidationErrors('employment_type');
        $this->patchJson($url, ['timezone' => 'Mars/Base'])->assertJsonValidationErrors('timezone');
        $this->patchJson($url, ['bio' => str_repeat('a', 1001)])->assertJsonValidationErrors('bio');
        $this->patchJson($url, ['github_url' => 'javascript:alert(1)'])->assertJsonValidationErrors('github_url');
        $this->patchJson($url, ['date_of_birth' => now()->subYears(5)->toDateString()])->assertJsonValidationErrors('date_of_birth');
        $this->patchJson($url, ['date_of_birth' => '1850-01-01'])->assertJsonValidationErrors('date_of_birth');
        $this->patchJson($url, ['join_date' => '1990-01-01'])->assertJsonValidationErrors('join_date');
        $this->patchJson($url, ['join_date' => '2025-06-01', 'probation_end_date' => '2025-05-01'])->assertJsonValidationErrors('probation_end_date');
        $this->patchJson($url, ['weekly_capacity_hours' => 0])->assertJsonValidationErrors('weekly_capacity_hours');
        $this->patchJson($url, ['team_lead_id' => 999999])->assertJsonValidationErrors('team_lead_id');

        // Lists: level enum, duplicate names (case-insensitive), the 30-skill cap, date order.
        $this->patchJson($url, ['skills' => [['name' => 'PHP', 'level' => 'guru']]])->assertJsonValidationErrors('skills.0.level');
        $this->patchJson($url, ['skills' => [['name' => 'PHP', 'level' => 'expert'], ['name' => 'php', 'level' => 'beginner']]])->assertJsonValidationErrors('skills.0.name');
        $this->patchJson($url, ['skills' => [['name' => 'PHP', 'level' => 'expert', 'years_experience' => 99]]])->assertJsonValidationErrors('skills.0.years_experience');
        $many = collect(range(1, 31))->map(fn (int $i): array => ['name' => "Skill {$i}", 'level' => 'beginner'])->all();
        $this->patchJson($url, ['skills' => $many])->assertJsonValidationErrors('skills');
        $this->patchJson($url, ['skills' => array_slice($many, 0, 30)])->assertOk();
        $this->patchJson($url, ['educations' => [['degree' => 'BS', 'institution' => 'UET', 'start_date' => '2020-01-01', 'end_date' => '2019-01-01']]])
            ->assertJsonValidationErrors('educations.0.end_date');
        $this->patchJson($url, ['experiences' => [['title' => '', 'company' => 'Acme']]])->assertJsonValidationErrors('experiences.0.title');
        $this->patchJson($url, ['certifications' => [['name' => 'X', 'issued_at' => '2024-01-01', 'expires_at' => '2023-01-01']]])
            ->assertJsonValidationErrors('certifications.0.expires_at');
        $this->patchJson($url, ['checklist' => ['nda_signed' => 'maybe']])->assertJsonValidationErrors('checklist.nda_signed');

        // The onboarding checklist merges instead of replacing.
        $this->patchJson($url, ['checklist' => ['nda_signed' => true]])->assertOk()->assertJsonPath('employee.profile.checklist.nda_signed', true);
        $this->patchJson($url, ['checklist' => ['equipment_given' => true]])->assertOk()
            ->assertJsonPath('employee.profile.checklist.nda_signed', true)
            ->assertJsonPath('employee.profile.checklist.equipment_given', true)
            ->assertJsonPath('employee.profile.checklist.cnic_verified', false);
    }

    public function test_completeness_is_computed_on_the_server_and_lists_what_is_missing(): void
    {
        $empty = User::factory()->create(['role' => Role::Employee]);
        $full = $this->employeeWithProfile();
        foreach (['cnic_front' => $this->png(), 'cnic_back' => $this->png('back.png'), 'cv' => $this->pdf()] as $type => $file) {
            $this->actingAs($this->admin)->post("/api/admin/employees/{$full->id}/documents", ['file' => $file, 'type' => $type], ['Accept' => 'application/json'])->assertCreated();
        }

        $this->getJson("/api/admin/employees/{$empty->id}")->assertJsonPath('employee.completeness.percent', 0);
        $detail = $this->getJson("/api/admin/employees/{$full->id}")->assertOk();
        $this->assertGreaterThan(70, $detail->json('employee.completeness.percent'));
        $this->assertContains('Profile photo', $detail->json('employee.completeness.missing'));
        $this->assertNotContains('CNIC', $detail->json('employee.completeness.missing'));
        $this->assertNotContains('CV', $detail->json('employee.completeness.missing'));

        // The employees list shows the summary (code, department, availability, status, completeness).
        $row = collect($this->getJson('/api/admin/users?role=employee')->assertOk()->json('users'))->firstWhere('id', $full->id);
        $this->assertSame('web', $row['profile']['department']);
        $this->assertSame('probation', $row['profile']['employment_status']);
        $this->assertSame($detail->json('employee.completeness.percent'), $row['profile']['completeness']);
        $this->assertStringStartsWith('EMP-', $row['profile']['employee_code']);
    }

    public function test_sensitive_columns_never_serialise_from_the_model(): void
    {
        $profile = $this->employeeWithProfile()->employeeProfile;
        $array = $profile->toArray();

        foreach (['cnic', 'cnic_hash', 'bank_account', 'pay_rate', 'internal_notes'] as $key) {
            $this->assertArrayNotHasKey($key, $array);
        }
        $this->assertSame('3410112345671', $profile->cnic);
        $this->assertSame('********************7702', $profile->bankAccountMasked());
        $this->assertSame(64, strlen($profile->cnic_hash));
        $this->assertNull(EmployeeProfile::normalizeCnic('12-34'));
    }

    public function test_employee_codes_are_unique_and_sequential(): void
    {
        Notification::fake();
        $a = $this->createEmployee(['name' => 'A', 'email' => 'a@example.com'])->assertCreated()->json('employee.profile.employee_code');
        $b = $this->createEmployee(['name' => 'B', 'email' => 'b@example.com'])->assertCreated()->json('employee.profile.employee_code');

        $this->assertSame('EMP-0001', $a);
        $this->assertSame('EMP-0002', $b);
    }

    public function test_admin_can_upload_and_remove_an_employee_photo_through_the_existing_avatar_service(): void
    {
        $employee = User::factory()->create(['role' => Role::Employee]);

        $this->actingAs($this->admin)->post("/api/admin/employees/{$employee->id}/photo", ['photo' => $this->png('me.png')], ['Accept' => 'application/json'])
            ->assertOk();
        $this->assertNotNull($employee->fresh()->avatar_url);
        $this->getJson("/api/admin/employees/{$employee->id}")->assertJsonPath('employee.photo_url', $employee->fresh()->avatar_url);

        $this->post("/api/admin/employees/{$employee->id}/photo", ['photo' => UploadedFile::fake()->createWithContent('x.png', '<html/>')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('photo');

        $this->deleteJson("/api/admin/employees/{$employee->id}/photo")->assertOk()->assertJsonPath('employee.photo_url', null);
    }
}
