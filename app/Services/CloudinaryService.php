<?php

namespace App\Services;

use Cloudinary\Cloudinary;
use Cloudinary\Asset\DeliveryType;
use Cloudinary\Configuration\UrlConfig;
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
                'exception' => get_class($e),
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

    public function uploadPrivateImageFile(
        UploadedFile $file,
        string $folder,
        ?string $publicId = null
    ): array {
        return $this->uploadSource(
            $file->getRealPath(),
            $folder,
            $publicId,
            'image',
            $file->getMimeType(),
            DeliveryType::AUTHENTICATED
        );
    }

    private function uploadSource(
        string $source,
        string $folder,
        ?string $public_id,
        string $resourceType,
        ?string $mimeType = null,
        string $deliveryType = DeliveryType::UPLOAD
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

            if ($deliveryType !== DeliveryType::UPLOAD) {
                $options['type'] = $deliveryType;
            }

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

            $secureUrl = $result['secure_url'] ?? null;
            $publicId = $result['public_id'] ?? null;
            $uploadedResourceType = $result['resource_type'] ?? null;
            $parsedSecureUrl = is_string($secureUrl) ? parse_url($secureUrl) : false;
            if (
                !is_array($parsedSecureUrl)
                || ($parsedSecureUrl['scheme'] ?? null) !== 'https'
                || ($parsedSecureUrl['host'] ?? null) !== 'res.cloudinary.com'
                || !is_string($publicId)
                || $publicId === ''
                || !is_string($uploadedResourceType)
                || $uploadedResourceType === ''
            ) {
                \Log::error('Cloudinary returned invalid upload metadata.', [
                    'folder' => $folder,
                    'mime_type' => $mimeType,
                ]);

                return [
                    'success' => false,
                    'message' => 'Cloudinary returned an invalid image reference.',
                ];
            }

            \Log::info('Cloudinary upload successful', [
                'resource_type' => $uploadedResourceType,
            ]);

            return [
                'success' => true,
                'url' => $secureUrl,
                'public_id' => $publicId,
                'resource_type' => $uploadedResourceType,
            ];
        } catch (\Throwable $e) {
            $reason = $this->safeProviderFailureReason($e);
            \Log::error('Cloudinary upload error', [
                'reason' => $reason,
                'exception' => get_class($e),
                'folder' => $folder,
                'mime_type' => $mimeType,
            ]);
            \Log::channel('stderr')->error('Cloudinary upload failed.', [
                'reason' => $reason,
                'exception' => get_class($e),
                'folder' => $folder,
                'mime_type' => $mimeType,
            ]);
            
            return [
                'success' => false,
                'message' => $reason,
            ];
        }
    }

    /**
     * Delete a file from Cloudinary
     *
     * @param string $public_id
     * @return boolean
     */
    public function deleteFile(
        string $public_id,
        string $resourceType = 'image',
        string $deliveryType = DeliveryType::UPLOAD
    ): bool
    {
        try {
            if (!$this->cloudinary) {
                return false;
            }

            $this->cloudinary->uploadApi()->destroy($public_id, [
                'resource_type' => $resourceType,
                'type' => $deliveryType,
            ]);
            return true;
        } catch (\Throwable $e) {
            \Log::error('Cloudinary delete failed.', [
                'exception' => get_class($e),
                'resource_type' => $resourceType,
            ]);
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
            $storedUrl = parse_url($public_id);
            if (
                is_array($storedUrl)
                && ($storedUrl['scheme'] ?? null) === 'https'
                && ($storedUrl['host'] ?? null) === 'res.cloudinary.com'
            ) {
                return $public_id;
            }

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
        } catch (\Throwable $e) {
            \Log::warning('Failed to generate Cloudinary URL', [
                'exception' => get_class($e),
                'resource_type' => $resourceType,
            ]);
            return null;
        }
    }

    public function generateAuthenticatedImageUrl(string $publicId): ?string
    {
        try {
            if (!$this->cloudinary) {
                \Log::warning('Cloudinary is not configured for authenticated image delivery.');
                return null;
            }

            $image = $this->cloudinary->image($publicId);
            $image->setUrlConfig(UrlConfig::FORCE_VERSION, false);

            return (string) $image
                ->deliveryType(DeliveryType::AUTHENTICATED)
                ->signUrl()
                ->toUrl();
        } catch (\Throwable $e) {
            \Log::error('Failed to generate authenticated Cloudinary image URL.', [
                'exception' => get_class($e),
            ]);

            return null;
        }
    }

    private function safeProviderFailureReason(\Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());
        if (
            str_contains($message, 'api_key')
            || str_contains($message, 'api key')
            || str_contains($message, 'api_secret')
            || str_contains($message, 'signature')
            || str_contains($message, 'unauthorized')
        ) {
            return 'Cloudinary credentials were rejected.';
        }

        if (
            str_contains($message, 'invalid image')
            || str_contains($message, 'unsupported format')
            || str_contains($message, 'file size')
        ) {
            return 'Cloudinary rejected the image format or size.';
        }

        return 'Cloudinary could not process the uploaded file.';
    }
}
