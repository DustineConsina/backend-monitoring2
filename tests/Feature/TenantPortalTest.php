<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns portal data for the authenticated tenant', function () {
    $user = User::create([
        'name' => 'Tenant User',
        'email' => 'tenant@example.com',
        'password' => 'password',
        'role' => 'tenant',
        'status' => 'active',
    ]);

    Tenant::create([
        'user_id' => $user->id,
        'tenant_code' => 'TEN-TEST-0001',
        'business_name' => 'Tenant Business',
        'contact_person' => 'Tenant User',
        'contact_number' => '09123456789',
        'status' => 'active',
    ]);

    $login = $this->postJson('/api/login', [
        'email' => 'tenant@example.com',
        'password' => 'password',
    ]);

    $login->assertOk()
        ->assertJsonPath('user.role', 'tenant');

    $this->withToken($login->json('token'))
        ->getJson('/api/tenant/portal')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.tenant_code', 'TEN-TEST-0001')
        ->assertJsonPath('data.business_name', 'Tenant Business')
        ->assertJsonPath('data.email', 'tenant@example.com')
        ->assertJsonPath('data.contracts', []);
});
