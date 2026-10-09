<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\RentalSpace;
use App\Models\Tenant;
use App\Services\CloudinaryService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class MigrateLocalUploadsToCloudinary extends Command
{
    protected $signature = 'assets:migrate-local-to-cloudinary {--dry-run : List local uploads without changing them}';

    protected $description = 'Move existing local rental-space, tenant, QR, and contract uploads to Cloudinary';

    public function handle(CloudinaryService $cloudinary): int
    {
        $assets = [
            [RentalSpace::class, 'map_image', 'rental-spaces', 'image'],
            [Tenant::class, 'profile_picture', 'profile-pictures', 'image'],
            [Tenant::class, 'qr_code', 'tenant-qr-codes', 'image'],
            [Contract::class, 'contract_file', 'contracts', 'raw'],
        ];

        $migrated = 0;
        $failed = 0;

        foreach ($assets as [$modelClass, $attribute, $folder, $resourceType]) {
            $modelClass::query()
                ->whereNotNull($attribute)
                ->where($attribute, '!=', '')
                ->chunkById(100, function ($records) use (
                    $cloudinary,
                    $attribute,
                    $folder,
                    $resourceType,
                    &$migrated,
                    &$failed
                ): void {
                    foreach ($records as $record) {
                        $path = (string) $record->getAttribute($attribute);
                        if (preg_match('/^https?:\/\//i', $path)) {
                            continue;
                        }

                        $disk = Storage::disk('public');
                        if (str_starts_with($path, $folder . '/') && !$disk->exists($path)) {
                            continue;
                        }

                        $recordLabel = class_basename($record) . ' #' . $record->getKey();
                        if (!$disk->exists($path)) {
                            $this->error("Local file missing for {$recordLabel}: {$path}");
                            $failed++;
                            continue;
                        }

                        if ($this->option('dry-run')) {
                            $this->line("Would upload {$recordLabel}: {$path}");
                            continue;
                        }

                        $localPath = $disk->path($path);
                        $file = new UploadedFile(
                            $localPath,
                            basename($path),
                            mime_content_type($localPath) ?: 'application/octet-stream',
                            null,
                            true
                        );
                        $extension = strtolower($file->getClientOriginalExtension());
                        $publicId = 'legacy-' . $record->getKey() . '-' . Str::uuid();
                        if ($resourceType === 'raw' && $extension !== '') {
                            $publicId .= '.' . $extension;
                        }

                        $upload = $cloudinary->uploadFile($file, $folder, $publicId, $resourceType);
                        if (!$upload['success']) {
                            $this->error(
                                "Cloudinary upload failed for {$recordLabel}: "
                                . ($upload['message'] ?? 'Unknown error')
                            );
                            $failed++;
                            continue;
                        }

                        try {
                            $record->setAttribute($attribute, $upload['public_id']);
                            $record->save();
                        } catch (Throwable $error) {
                            $cloudinary->deleteFile($upload['public_id'], $resourceType);
                            throw $error;
                        }

                        $this->info("Migrated {$recordLabel}");
                        $migrated++;
                    }
                });
        }

        $this->info("Migrated {$migrated} upload(s); {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
