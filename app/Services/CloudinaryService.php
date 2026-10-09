<?php

namespace App\Services;

use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;

class CloudinaryService
{
    private $cloudinary;
    private $cloudName;

    public function __construct()
    {
        try {
            // Parse CLOUDINARY_URL if set
            $cloudinaryUrl = config('services.cloudinary.url');
            
            if ($cloudinaryUrl) {
                // Parse cloudinary://key:secret@cloudname format
                $this->cloudinary = new Cloudinary($cloudinaryUrl);
                // Extract cloud name from URL
                preg_match('/cloudinary:\/\/[^:]+:[^@]+@([^\/]+)/', $cloudinaryUrl, $matches);
                $this->cloudName = $matches[1] ?? config('services.cloudinary.cloud_name', '');
            } else {
                // Fallback to individual env variables
                $this->cloudName = config('services.cloudinary.cloud_name', '');
                $apiKey = config('services.cloudinary.api_key', '');
                $apiSecret = config('services.cloudinary.api_secret', '');
                
                if (!$this->cloudName || !$apiKey || !$apiSecret) {
                    \Log::warning('Cloudinary credentials not fully configured', [
                        'has_cloud_name' => (bool)$this->cloudName,
                        'has_api_key' => (bool)$apiKey,
                        'has_api_secret' => (bool)$apiSecret,
                    ]);
                    $this->cloudinary = null;
                    return;
                }
                
                $this->cloudinary = new Cloudinary([
                    'cloud' => [
                        'cloud_name' => $this->cloudName,
                        'api_key' => $apiKey,
                        'api_secret' => $apiSecret,
                    ]
                ]);
            }
            
            \Log::info('CloudinaryService initialized successfully', [
                'cloud_name' => $this->cloudName
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to initialize CloudinaryService', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->cloudinary = null;
            $this->cloudName = '';
        }
    }

    /**
     * Upload a file to Cloudinary
     *
     * @param UploadedFile $file
     * @param string $folder
     * @param string $public_id
     * @return array
     */
    public function uploadFile(
        UploadedFile $file,
        string $folder = 'profile-pictures',
        ?string $public_id = null,
        string $resourceType = 'auto'
    ): array
    {
        return $this->uploadSource(
            $file->getRealPath(),
            $folder,
            $public_id,
            $resourceType,
            $file->getMimeType()
        );
    }

    public function uploadContent(
        string $content,
        string $folder,
        string $public_id,
        string $mimeType,
        string $resourceType = 'image'
    ): array
    {
        $source = 'data:' . $mimeType . ';base64,' . base64_encode($content);

        return $this->uploadSource($source, $folder, $public_id, $resourceType, $mimeType);
    }

    private function uploadSource(
        string $source,
        string $folder,
        ?string $public_id,
        string $resourceType,
        ?string $mimeType = null
    ): array
    {
        try {
            if (!$this->cloudinary) {
                return [
                    'success' => false,
                    'message' => 'Cloudinary is not configured for this environment.',
                ];
            }

            $options = [
                'folder' => $folder,
                'resource_type' => $resourceType,
            ];

            if ($resourceType === 'image' || $resourceType === 'auto') {
                $options['quality'] = 'auto';
                $options['fetch_format'] = 'auto';
            }

            if ($public_id) {
                $options['public_id'] = $public_id;
            }

            $result = $this->cloudinary->uploadApi()->upload(
                $source,
                $options
            );

            \Log::info('Cloudinary upload successful', [
                'public_id' => $result['public_id'],
                'url' => $result['secure_url'],
                'resource_type' => $result['resource_type'],
            ]);

            return [
                'success' => true,
                'url' => $result['secure_url'],
                'public_id' => $result['public_id'],
                'resource_type' => $result['resource_type'],
            ];
        } catch (\Exception $e) {
            \Log::error('Cloudinary upload error', [
                'error' => $e->getMessage(),
                'folder' => $folder,
                'mime_type' => $mimeType,
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Delete a file from Cloudinary
     *
     * @param string $public_id
     * @return boolean
     */
    public function deleteFile(string $public_id, string $resourceType = 'image'): bool
    {
        try {
            if (!$this->cloudinary) {
                return false;
            }

            $this->cloudinary->uploadApi()->destroy($public_id, [
                'resource_type' => $resourceType,
            ]);
            return true;
        } catch (\Exception $e) {
            \Log::error('Cloudinary delete error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate an optimized URL for an image
     *
     * @param string $public_id
     * @param int $width
     * @param int $height
     * @return string|null
     */
    public function generateUrl(
        string $public_id,
        int $width = 200,
        int $height = 200,
        string $resourceType = 'image'
    ): ?string
    {
        try {
            if (!$this->cloudName) {
                \Log::warning('Cloud name not configured for Cloudinary');
                return null;
            }

            if ($resourceType === 'raw') {
                return "https://res.cloudinary.com/{$this->cloudName}/raw/upload/{$public_id}";
            }

            $transformations = "f_auto,q_auto,w_{$width},h_{$height},c_fill";
            $url = "https://res.cloudinary.com/{$this->cloudName}/{$resourceType}/upload/{$transformations}/{$public_id}";
            
            return $url;
        } catch (\Exception $e) {
            \Log::warning('Failed to generate Cloudinary URL', [
                'public_id' => $public_id,
                'error' => $e->getMessage()
            ]);
            // Return null if unable to generate URL
            return null;
        }
    }
}
