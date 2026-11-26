<?php

use App\Models\Complaint;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('issues opaque QR tokens and resolves tenant complaints from the token only', function () {
    $admin = User::create([
        'name' => 'Admin User',
        'email' => 'admin@example.com',
        'password' => 'password',
        'role' => 'admin',
        'status' => 'active',
    ]);

    $tenantUser = User::create([
        'name' => 'Tenant User',
        'email' => 'tenant@example.com',
        'password' => 'tenant-password',
        'role' => 'tenant',
        'status' => 'active',
    ]);
    $tenant = Tenant::create([
        'user_id' => $tenantUser->id,
        'tenant_code' => 'TEN-TEST-0001',
        'qr_password_hash' => Hash::make('12345678'),
        'qr_access_code' => '12345678',
        'business_name' => 'Tenant Business',
        'contact_person' => 'Tenant User',
        'contact_number' => '09123456789',
        'status' => 'active',
    ]);

    $otherTenantUser = User::create([
        'name' => 'Other Tenant',
        'email' => 'other@example.com',
        'password' => 'other-password',
        'role' => 'tenant',
        'status' => 'active',
    ]);
    $otherTenant = Tenant::create([
        'user_id' => $otherTenantUser->id,
        'tenant_code' => 'TEN-TEST-0002',
        'qr_password_hash' => Hash::make('87654321'),
        'qr_access_code' => '87654321',
        'business_name' => 'Other Business',
        'contact_person' => 'Other Tenant',
        'contact_number' => '09111111111',
        'status' => 'active',
    ]);

    $token = $this->actingAs($admin, 'sanctum')
        ->getJson("/api/tenants/{$tenant->id}/qr-token")
        ->assertOk()
        ->json('token');
    $secondToken = $this->getJson("/api/tenants/{$tenant->id}/qr-token")
        ->assertOk()
        ->json('token');

    expect($token)->toBeString()
        ->and($token)->not->toBe($secondToken)
        ->and($token)->toMatch('/\A[A-Za-z0-9_-]+\z/')
        ->and($token)->not->toBe((string) $tenant->id)
        ->and($token)->not->toContain($tenant->tenant_code);

    $this->postJson('/api/public/tenant-scan', ['token' => $token])
        ->assertUnprocessable();

    $this->postJson('/api/public/tenant-scan', [
        'token' => $token,
        'access_code' => '00000000',
    ])->assertForbidden()
        ->assertJsonPath('message', 'The QR password is incorrect.');

    $this->postJson('/api/public/tenant-scan', [
        'token' => $token,
        'access_code' => '12345678',
    ])
        ->assertOk()
        ->assertJsonPath('data.tenant_code', 'TEN-TEST-0001')
        ->assertJsonMissingPath('data.password');

    $this->postJson('/api/public/tenant-scan/complaints', [
        'token' => $token,
        'access_code' => '12345678',
        'tenant_id' => $otherTenant->id,
        'category' => 'other',
        'subject' => 'Stall maintenance',
        'message' => 'Please check the leaking water pipe near the entrance.',
    ])->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Your message has been submitted successfully.');

    $complaint = Complaint::query()->latest('id')->firstOrFail();
    expect($complaint->tenant_id)->toBe($tenant->id)
        ->and($complaint->admin_read)->toBeFalse();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/complaints')
        ->assertOk()
        ->assertJsonFragment(['tenant_code' => 'TEN-TEST-0001'])
        ->assertJsonFragment(['subject' => 'Stall maintenance']);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/complaints/unread-count')
        ->assertOk()
        ->assertJsonPath('count', 1);
});

it('rejects invalid tenant QR tokens for scans and complaint submissions', function () {
    $this->postJson('/api/public/tenant-scan', [
        'token' => 'invalid-token',
        'access_code' => '12345678',
    ])
        ->assertNotFound()
        ->assertJsonPath('success', false);

    $this->postJson('/api/public/tenant-scan/complaints', [
        'token' => 'invalid-token',
        'access_code' => '12345678',
        'category' => 'other',
        'subject' => 'Stall maintenance',
        'message' => 'Please check the leaking water pipe near the entrance.',
    ])->assertNotFound()
        ->assertJsonPath('success', false);

    expect(Complaint::count())->toBe(0);
});

it('returns legacy plaintext QR passwords in the authorized tenant profile without a server error', function () {
    $admin = User::create([
        'name' => 'Admin User',
        'email' => 'admin@example.com',
        'password' => 'password',
        'role' => 'admin',
        'status' => 'active',
    ]);
    $tenantUser = User::create([
        'name' => 'Tenant User',
        'email' => 'tenant@example.com',
        'password' => 'tenant-password',
        'role' => 'tenant',
        'status' => 'active',
    ]);
    $tenant = Tenant::create([
        'user_id' => $tenantUser->id,
        'tenant_code' => 'TEN-TEST-0001',
        'qr_password_hash' => Hash::make('12345678'),
        'qr_access_code' => '12345678',
        'business_name' => 'Tenant Business',
        'contact_person' => 'Tenant User',
        'contact_number' => '09123456789',
        'status' => 'active',
    ]);

    DB::table('tenants')->where('id', $tenant->id)->update(['qr_access_code' => '12345678']);

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/tenants/{$tenant->id}")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.qr_access_code', '12345678');
});

it('resets the QR password without changing the tenant account password', function () {
    $admin = User::create([
        'name' => 'Admin User',
        'email' => 'admin@example.com',
        'password' => 'password',
        'role' => 'admin',
        'status' => 'active',
    ]);
    $tenantUser = User::create([
        'name' => 'Tenant User',
        'email' => 'tenant@example.com',
        'password' => 'tenant-password',
        'role' => 'tenant',
        'status' => 'active',
    ]);
    $tenant = Tenant::create([
        'user_id' => $tenantUser->id,
        'tenant_code' => 'TEN-TEST-0001',
        'qr_password_hash' => Hash::make('12345678'),
        'qr_access_code' => '12345678',
        'business_name' => 'Tenant Business',
        'contact_person' => 'Tenant User',
        'contact_number' => '09123456789',
        'status' => 'active',
    ]);
    $oldAccountPassword = $tenantUser->password;

    $response = $this->actingAs($admin, 'sanctum')
        ->postJson("/api/tenants/{$tenant->id}/reset-password")
        ->assertOk()
        ->assertJsonPath('success', true);

    $newQrPassword = $response->json('temporaryPassword');
    expect($newQrPassword)->toMatch('/\A\d{8}\z/');
    expect(Hash::check($newQrPassword, $tenant->fresh()->qr_password_hash))->toBeTrue();
    expect($tenantUser->fresh()->password)->toBe($oldAccountPassword);
    expect(Hash::check('tenant-password', $tenantUser->fresh()->password))->toBeTrue();
});
