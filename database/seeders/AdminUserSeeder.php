<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\QueryException;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $users = [
            [
                'email' => 'admin@pfda.gov.ph',
                'name' => 'Dustine Consina',
                'role' => 'admin',
                'phone' => '09123456789',
            ],
            [
                'email' => 'staff@pfda.gov.ph',
                'name' => 'Jenny Rose Formanes',
                'role' => 'staff',
                'phone' => '09123456790',
            ],
            [
                'email' => 'cashier@pfda.gov.ph',
                'name' => 'Keanalyn Buiza',
                'role' => 'cashier',
                'phone' => '09123456791',
            ],
        ];

        foreach ($users as $userData) {
            try {
                $user = User::firstOrNew(['email' => $userData['email']]);
                $isNewUser = !$user->exists;

                $user->name = $userData['name'];
                $user->role = $userData['role'];
                $user->phone = $userData['phone'];
                $user->address = 'PFDA Office, Bulan, Sorsogon';
                $user->status = 'active';

                if ($isNewUser) {
                    $user->password = Hash::make('password123');
                }

                $user->save();
                $this->command->line("✓ {$userData['email']} ({$userData['role']}) ready");
            } catch (QueryException $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $this->command->line("⚠️  {$userData['email']} already exists");
                } elseif (strpos($e->getMessage(), 'Data truncated') !== false || strpos($e->getMessage(), 'truncated for column') !== false) {
                    $this->command->error("✗ Failed to create {$userData['email']}: The '{$userData['role']}' role is not supported by the database enum. Run migrations first: php artisan migrate");
                    \Illuminate\Support\Facades\Log::error('AdminUserSeeder: Role enum error', [
                        'email' => $userData['email'],
                        'role' => $userData['role'],
                        'error' => $e->getMessage()
                    ]);
                } else {
                    $this->command->error("✗ Failed to create {$userData['email']}: " . $e->getMessage());
                    \Illuminate\Support\Facades\Log::error('AdminUserSeeder error', [
                        'email' => $userData['email'],
                        'error' => $e->getMessage()
                    ]);
                }
            } catch (\Exception $e) {
                $this->command->error("✗ Unexpected error for {$userData['email']}: " . $e->getMessage());
                \Illuminate\Support\Facades\Log::error('AdminUserSeeder unexpected error', [
                    'email' => $userData['email'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->command->info('✓ Admin, staff, and cashier users setup complete');
    }
}
