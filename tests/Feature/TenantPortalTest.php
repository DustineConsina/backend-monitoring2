<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('preserves authenticated tenant portal functionality', function () {
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

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/tenant/portal')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.tenant_code', 'TEN-TEST-0001')
        ->assertJsonPath('data.business_name', 'Tenant Business')
        ->assertJsonPath('data.email', 'tenant@example.com')
        ->assertJsonPath('data.contracts', []);
});

it('rejects tenant password login without modifying the stored password', function () {
    $user = User::create([
        'name' => 'Tenant User',
        'email' => 'tenant@example.com',
        'password' => 'password',
        'role' => 'tenant',
        'status' => 'active',
    ]);
    $storedPassword = $user->password;

    $this->postJson('/api/login', [
        'email' => 'tenant@example.com',
        'password' => 'password',
    ])->assertUnauthorized();

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
    expect($user->fresh()->password)->toBe($storedPassword);
});

it('continues to allow administrator password login', function () {
    User::create([
        'name' => 'Admin User',
        'email' => 'admin@example.com',
        'password' => 'password',
        'role' => 'admin',
        'status' => 'active',
    ]);

    $this->postJson('/api/login', [
        'email' => 'admin@example.com',
        'password' => 'password',
    ])->assertOk()
        ->assertJsonPath('user.role', 'admin')
        ->assertJsonStructure(['token']);
});
