<?php

namespace Tests\Feature;

use App\Models\EmsReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SsoIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'ems-test-shared-secret-with-at-least-32-characters';
    private string $module = '45323319-3126-4717-8ce2-95b8fb5f055f';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'gpha_sso.shared_secret' => $this->secret,
            'gpha_sso.issuer' => 'GPHACentralLogin',
            'gpha_sso.allowed_audiences' => ['EMS', 'CentralLogin'],
            'gpha_sso.module_id' => $this->module,
            'gpha_sso.app_id' => 'EMS',
            'gpha_sso.app_key' => 'test-app-key',
            'gpha_sso.permissions_endpoint' => 'http://central.test/api/app-access/user-permissions',
        ]);
    }

    public function test_valid_central_login_token_creates_session_and_shadow_user(): void
    {
        $id = (string) Str::uuid();
        Http::fake(['*' => Http::response(['permissionCodes' => [
            'EMS.AmbulanceFleet.View',
            'EMS.EMSReports.View',
        ]], 200)]);

        $token = $this->token([
            'UserId' => $id,
            'username' => 'ems.user',
            'Staff Name' => 'EMS User',
            'branch_id' => ['1'],
            'branch_code' => ['HQ'],
            'branch_name' => ['Headquarters'],
        ]);

        $response = $this->get(route('sso.login', ['token' => $token]));

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', ['sso_user_id' => $id, 'sso_username' => 'ems.user']);
        $response->assertSessionHas('sso.permissions.ambulancefleet', ['view']);
        $response->assertSessionHas('sso.active_branch_code', 'HQ');
        Http::assertSent(fn ($request) => $request->hasHeader('X-App-Id', 'EMS')
            && $request['moduleId'] === $this->module);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $token = $this->token(
            ['UserId' => (string) Str::uuid()],
            'different-secret-with-more-than-32-characters',
        );

        $this->get(route('sso.login', ['token' => $token]))->assertStatus(401);
        $this->assertGuest();
    }

    public function test_backend_guard_blocks_unassigned_component(): void
    {
        $user = User::factory()->create(['sso_user_id' => (string) Str::uuid()]);

        $this->actingAs($user)->withSession([
            'sso.permissions' => ['ambulancefleet' => ['view']],
            'sso.permissions_synced_at' => now()->timestamp,
        ])->get(route('ems.module', ['module' => 'reports']))->assertForbidden();
    }

    public function test_report_approval_requires_the_exact_approve_permission(): void
    {
        $user = User::factory()->create(['sso_user_id' => (string) Str::uuid()]);
        $report = EmsReport::withoutGlobalScopes()->create([
            'type' => 'mileage',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-07',
            'status' => 'draft',
            'snapshot' => [],
            'branch_code' => 'HQ',
            'prepared_by' => $user->id,
        ]);
        $session = [
            'sso.permissions' => ['emsreports' => ['view', 'manage']],
            'sso.permissions_synced_at' => now()->timestamp,
            'sso.active_branch_code' => 'HQ',
            'sso.branches.codes' => ['HQ'],
        ];

        $this->actingAs($user)->withSession($session)
            ->patch(route('ems.reports.approve', $report))
            ->assertForbidden();

        $session['sso.permissions.emsreports'][] = 'approve';
        $this->actingAs($user)->withSession($session)
            ->patch(route('ems.reports.approve', $report))
            ->assertRedirect();

        $this->assertDatabaseHas('ems_reports', [
            'id' => $report->id,
            'status' => 'approved',
            'approved_by' => $user->id,
        ]);
    }

    public function test_audit_view_and_export_have_independent_permissions(): void
    {
        $user = User::factory()->create(['sso_user_id' => (string) Str::uuid()]);
        $session = [
            'sso.permissions' => ['emsactivityandaudit' => ['view']],
            'sso.permissions_synced_at' => now()->timestamp,
            'sso.active_branch_code' => 'HQ',
        ];

        $this->actingAs($user)->withSession($session)->get(route('ems.audit'))->assertOk();
        $this->actingAs($user)->withSession($session)->get(route('ems.audit.export'))->assertForbidden();

        $session['sso.permissions.emsactivityandaudit'][] = 'export';
        $this->actingAs($user)->withSession($session)->get(route('ems.audit.export'))->assertDownload();
    }

    private function token(array $claims, string $secret = ''): string
    {
        $header = $this->base64Url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->base64Url(json_encode($claims + [
            'moduleId' => $this->module,
            'iss' => 'GPHACentralLogin',
            'aud' => 'CentralLogin',
            'iat' => time(),
            'exp' => time() + 180,
        ]));
        $signature = hash_hmac('sha256', "$header.$payload", $secret ?: $this->secret, true);

        return "$header.$payload.".$this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
