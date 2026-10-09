<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResetAdminPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_admin_with_a_hidden_interactive_password(): void
    {
        $this->artisan('admin:reset-password')
            ->expectsQuestion('New admin password (minimum 12 characters)', 'a-secure-test-password')
            ->expectsQuestion('Confirm new admin password', 'a-secure-test-password')
            ->expectsOutputToContain('Administrator account Joannaruby@pfda.gov.ph is ready')
            ->assertExitCode(0);

        $admin = User::where('email', 'Joannaruby@pfda.gov.ph')->firstOrFail();

        $this->assertSame('admin', $admin->role);
        $this->assertSame('active', $admin->status);
        $this->assertSame('Joanna Ruby Layosa', $admin->name);
        $this->assertTrue(Hash::check('a-secure-test-password', $admin->password));
    }

    public function test_command_resets_an_existing_user_and_promotes_it_to_active_admin(): void
    {
        $user = User::factory()->create([
            'email' => 'Joannaruby@pfda.gov.ph',
            'role' => 'staff',
            'status' => 'inactive',
            'password' => 'old-password',
        ]);

        $this->artisan('admin:reset-password')
            ->expectsQuestion('New admin password (minimum 12 characters)', 'a-different-secure-password')
            ->expectsQuestion('Confirm new admin password', 'a-different-secure-password')
            ->assertExitCode(0);

        $user->refresh();

        $this->assertSame('admin', $user->role);
        $this->assertSame('active', $user->status);
        $this->assertSame('Joanna Ruby Layosa', $user->name);
        $this->assertTrue(Hash::check('a-different-secure-password', $user->password));
    }
}
