<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetAdminPassword extends Command
{
    protected $signature = 'admin:reset-password {email=Joannaruby@pfda.gov.ph}';

    protected $description = 'Create or reset an administrator account using a hidden interactive password prompt';

    public function handle(): int
    {
        if (!$this->input->isInteractive()) {
            $this->error('Run this command from an interactive terminal so the password can be entered securely.');

            return self::FAILURE;
        }

        $email = trim((string) $this->argument('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Enter a valid administrator email address.');

            return self::FAILURE;
        }

        $password = $this->secret('New admin password (minimum 12 characters)');
        $confirmation = $this->secret('Confirm new admin password');

        if (!is_string($password) || strlen($password) < 12) {
            $this->error('The password must be at least 12 characters long.');

            return self::FAILURE;
        }

        if (!is_string($confirmation) || !hash_equals($password, $confirmation)) {
            $this->error('The password confirmation does not match.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($email, $password): void {
            $user = User::firstOrNew(['email' => $email]);
            $user->name = 'Joanna Ruby Layosa';
            $user->role = 'admin';
            $user->status = 'active';
            $user->password = $password;
            $user->save();
            $user->tokens()->delete();
        });

        $this->info("Administrator account {$email} is ready. Sign in with the password you entered.");

        return self::SUCCESS;
    }
}
