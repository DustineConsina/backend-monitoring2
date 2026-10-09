<?php

namespace App\Console\Commands;

use App\Models\RentalSpace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImportOfficialRentalSpaceInventory extends Command
{
    protected $signature = 'rental-spaces:import-official-inventory {--apply : Back up and apply the inventory changes}';

    protected $description = 'Import the official rental-space areas and monthly rates from the uploaded inventory sheets';

    private const INVENTORY = [
        'food_stall' => [
            ['FS-01', 'FS 01', 45.80, 5830.80],
            ['FS-02', 'FS 02', 39.00, 4965.09],
            ['FS-03', 'FS 03', 39.00, 4965.09],
            ['FS-04', 'FS 04', 39.00, 4965.09],
            ['FS-05', 'FS 05', 65.80, 7597.93],
            ['FS-06', 'FS 06', 39.00, 4965.09],
            ['FS-07', 'FS 07', 39.00, 4965.09],
            ['FS-08', 'FS 08', 39.00, 4965.09],
            ['FS-09', 'FS 09', 39.00, 4965.09],
            ['FS-10', 'FS 10', 45.80, 5830.80],
        ],
        'market_hall' => [
            ['MH-1-A', '1-A', 29.20, 2774.00],
            ['MH-1-B', '1-B', 29.20, 2774.00],
            ['MH-2', '2', 37.50, 0.00],
            ['MH-3-A', '3-A', 18.75, 0.00],
            ['MH-3-B', '3-B', 18.75, 0.00],
            ['MH-4', '4', 37.50, 0.00],
            ['MH-5-A', '5-A', 18.75, 0.00],
            ['MH-5-B', '5-B', 18.75, 0.00],
            ['MH-6-A', '6-A', 18.75, 1781.25],
            ['MH-6-B', '6-B', 18.75, 1781.25],
            ['MH-7-A', '7-A', 18.75, 1781.25],
            ['MH-7-B', '7-B', 18.75, 1781.25],
            ['MH-8-A', '8-A', 18.75, 1781.25],
            ['MH-8-B', '8-B', 18.75, 1781.25],
            ['MH-9', '9', 37.50, 3562.50],
            ['MH-10', '10', 37.50, 3562.50],
            ['MH-11-A', '11-A', 18.75, 1781.25],
            ['MH-11-B', '11-B', 18.75, 1781.25],
            ['MH-12-A', '12-A', 18.75, 1781.25],
            ['MH-12-B', '12-B', 18.75, 1781.25],
            ['MH-13', '13', 56.25, 5343.75],
            ['MH-15', '15', 37.50, 3562.50],
            ['MH-16', '16', 37.50, 3562.50],
            ['MH-17', '17', 37.50, 3562.50],
            ['MH-18-A', '18-A', 18.75, 1781.25],
            ['MH-18-B', '18-B', 18.75, 1781.25],
            ['MH-19', '19', 37.50, 3562.50],
            ['MH-20-A', '20-A', 18.75, 1781.25],
            ['MH-20-B', '20-B', 18.75, 1781.25],
            ['MH-21-A', '21-A', 18.75, 1781.25],
            ['MH-21-B', '21-B', 18.75, 1781.25],
            ['MH-22-A', '22-A', 18.75, 1781.25],
            ['MH-22-B', '22-B', 18.75, 1781.25],
            ['MH-23', '23', 37.50, 3562.50],
            ['MH-24-A', '24-A', 18.75, 1781.25],
            ['MH-24-B', '24-B', 18.75, 1781.25],
            ['MH-25-A', '25-A', 18.75, 1781.25],
            ['MH-25-B', '25-B', 18.75, 1781.25],
            ['MH-26', '26', 56.25, 5343.75],
        ],
        'banera_warehouse' => [
            ['BW1-01', 'BW1 01', 26.00, 2149.87],
            ['BW1-02', 'BW1 02', 26.00, 2149.87],
            ['BW1-03', 'BW1 03', 26.00, 2149.87],
            ['BW1-04', 'BW1 04', 26.00, 2149.87],
            ['BW1-05', 'BW1 05', 26.00, 2149.87],
            ['BW1-06', 'BW1 06', 26.00, 2149.87],
            ['BW2-07', 'BW2 07', 30.00, 2480.62],
            ['BW1-08', 'BW1 08', 26.00, 2149.87, 'BW2-08'],
            ['BW1-09', 'BW1 09', 26.00, 2149.87, 'BW2-09'],
            ['BW2-10', 'BW2 10', 26.00, 2149.87],
            ['BW1-11', 'BW1 11', 33.60, 2778.29, 'BW2-11'],
            ['BW2-12', 'BW2 12', 35.72, 2953.59],
        ],
    ];

    public function handle(): int
    {
        $plan = $this->buildPlan();
        if ($plan === null) {
            return self::FAILURE;
        }

        $this->table(
            ['Type', 'Existing code', 'Target code', 'Facility', 'Area (m²)', 'Monthly rate'],
            array_map(fn (array $row) => [
                $row['type'],
                $row['existing_code'],
                $row['space_code'],
                $row['facility'],
                number_format($row['size_sqm'], 2),
                '₱' . number_format($row['base_rental_rate'], 2),
            ], $plan['updates'])
        );

        $this->info(sprintf(
            'Inventory plan: %d spaces across %d types; %d obsolete, uncontracted warehouse record(s) eligible for removal.',
            count($plan['updates']),
            count(self::INVENTORY),
            count($plan['deletions'])
        ));

        if (!$this->option('apply')) {
            $this->comment('Dry run only. Re-run with --apply to back up and apply these changes.');
            return self::SUCCESS;
        }

        $backupName = 'backups/rental-spaces-before-import-' . now()->format('Ymd-His') . '.json';
        Storage::disk('local')->put(
            $backupName,
            RentalSpace::query()->orderBy('id')->get()->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        DB::transaction(function () use ($plan): void {
            foreach ($plan['updates'] as $row) {
                $space = $row['space'] ?? new RentalSpace();
                $space->space_code = $row['space_code'];
                $space->space_type = $row['type'];
                $space->name = $row['name'];
                $space->size_sqm = $row['size_sqm'];
                $space->base_rental_rate = $row['base_rental_rate'];
                $space->save();
            }

            foreach ($plan['deletions'] as $space) {
                $space->delete();
            }
        });

        $this->info('Rental-space inventory imported.');
        $this->line('Backup saved to ' . Storage::disk('local')->path($backupName));

        return self::SUCCESS;
    }

    private function buildPlan(): ?array
    {
        $updates = [];

        foreach (self::INVENTORY as $type => $rows) {
            $legacyPrefix = match ($type) {
                'food_stall' => 'FS-',
                'market_hall' => 'MH-',
                'banera_warehouse' => 'BW-',
            };

            foreach ($rows as $index => $row) {
                [$spaceCode, $facility, $sizeSqm, $monthlyRate, $previousCode] = array_pad($row, 5, null);
                $legacyCode = $legacyPrefix . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);

                $legacySpace = RentalSpace::query()->where('space_code', $legacyCode)->first();
                $previousSpace = $previousCode
                    ? RentalSpace::query()->where('space_code', $previousCode)->first()
                    : null;
                $space = RentalSpace::query()->where('space_code', $spaceCode)->first();
                $matchedIds = collect([$legacySpace, $previousSpace, $space])
                    ->filter()
                    ->pluck('id')
                    ->unique();

                if ($matchedIds->count() > 1) {
                    $this->error("Code {$spaceCode} already belongs to a different rental-space record.");
                    return null;
                }

                $space = $space ?? $previousSpace ?? $legacySpace;

                $updates[] = [
                    'space' => $space,
                    'existing_code' => $space?->space_code ?? '(new)',
                    'space_code' => $spaceCode,
                    'type' => $type,
                    'facility' => $facility,
                    'name' => match ($type) {
                        'food_stall' => 'Food Stall ' . $facility,
                        'market_hall' => 'Market Hall ' . $facility,
                        'banera_warehouse' => 'Bañera Warehouse ' . $facility,
                    },
                    'size_sqm' => $sizeSqm,
                    'base_rental_rate' => $monthlyRate,
                ];
            }
        }

        $deletions = [];
        foreach (['BW-013', 'BW-014'] as $obsoleteCode) {
            $space = RentalSpace::query()->where('space_code', $obsoleteCode)->first();
            if (!$space) {
                continue;
            }

            if ($space->contracts()->exists()) {
                $this->error("Cannot remove {$obsoleteCode}: it has linked contracts. Resolve its facility mapping first.");
                return null;
            }

            $deletions[] = $space;
        }

        return ['updates' => $updates, 'deletions' => $deletions];
    }
}