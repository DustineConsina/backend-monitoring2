<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $emailChanges = [
        ['admin@pfda.gov.ph', 'Joannaruby@pfda.gov.ph'],
        ['cashier@pfda.gov.ph', 'buizakeanalyn@pfda.gov.ph'],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach ($this->emailChanges as [$oldEmail, $newEmail]) {
                $this->renameEmail($oldEmail, $newEmail);
            }

            DB::table('users')
                ->where('email', 'Joannaruby@pfda.gov.ph')
                ->where('role', 'admin')
                ->update(['name' => 'Joanna Ruby Layosa', 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach ($this->emailChanges as [$oldEmail, $newEmail]) {
                $this->renameEmail($newEmail, $oldEmail);
            }
        });
    }

    private function renameEmail(string $from, string $to): void
    {
        $sourceExists = DB::table('users')->where('email', $from)->exists();
        $destinationExists = DB::table('users')->where('email', $to)->exists();

        if ($sourceExists && $destinationExists) {
            throw new \RuntimeException("Cannot rename {$from}: {$to} is already assigned to another account.");
        }

        if ($sourceExists) {
            DB::table('users')
                ->where('email', $from)
                ->update(['email' => $to, 'updated_at' => now()]);
        }
    }
};
