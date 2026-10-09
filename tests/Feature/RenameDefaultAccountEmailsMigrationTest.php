<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RenameDefaultAccountEmailsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_admin_and_cashier_addresses_are_renamed_without_changing_passwords(): void
    {
        DB::table('users')
            ->whereIn('email', [
                'admin@pfda.gov.ph',
                'Joannaruby@pfda.gov.ph',
                'cashier@pfda.gov.ph',
                'buizakeanalyn@pfda.gov.ph',
            ])
            ->delete();

        $admin = User::factory()->create([
            'email' => 'admin@pfda.gov.ph',
            'role' => 'admin',
            'password' => 'admin-test-password',
        ]);
        $cashier = User::factory()->create([
            'email' => 'cashier@pfda.gov.ph',
            'role' => 'cashier',
            'password' => 'cashier-test-password',
        ]);
        $adminPasswordHash = $admin->password;
        $cashierPasswordHash = $cashier->password;

        $migration = require database_path(
            'migrations/2026_10_09_000002_rename_default_admin_and_cashier_emails.php'
        );
        $migration->up();

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'email' => 'Joannaruby@pfda.gov.ph',
            'name' => 'Joanna Ruby Layosa',
            'password' => $adminPasswordHash,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $cashier->id,
            'email' => 'buizakeanalyn@pfda.gov.ph',
            'password' => $cashierPasswordHash,
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'admin@pfda.gov.ph']);
        $this->assertDatabaseMissing('users', ['email' => 'cashier@pfda.gov.ph']);
    }
}
