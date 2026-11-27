<?php

namespace Tests\Unit;

use App\Services\CloudinaryService;
use Cloudinary\Api\ApiResponse;
use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CloudinaryServiceTest extends TestCase
{
    public function test_raw_uploads_can_be_stored_and_resolved_as_cloudinary_urls(): void
    {
        $source = 'data:application/pdf;base64,' . base64_encode('pdf contents');
        $uploadApi = \Mockery::mock(UploadApi::class);
        $cloudinary = \Mockery::mock(Cloudinary::class);
        $cloudinary->shouldReceive('uploadApi')->once()->andReturn($uploadApi);
        $uploadApi->shouldReceive('upload')
            ->once()
            ->with($source, [
                'folder' => 'contracts',
                'resource_type' => 'raw',
                'public_id' => 'contract-test.pdf',
            ])
            ->andReturn(new ApiResponse([
                'secure_url' => 'https://res.cloudinary.com/demo/raw/upload/contracts/contract-test.pdf',
                'public_id' => 'contracts/contract-test.pdf',
                'resource_type' => 'raw',
            ], []));

        $service = (new \ReflectionClass(CloudinaryService::class))->newInstanceWithoutConstructor();
        $cloudinaryProperty = new \ReflectionProperty(CloudinaryService::class, 'cloudinary');
        $cloudinaryProperty->setValue($service, $cloudinary);
        $cloudNameProperty = new \ReflectionProperty(CloudinaryService::class, 'cloudName');
        $cloudNameProperty->setValue($service, 'demo');

        $result = $service->uploadContent(
            'pdf contents',
            'contracts',
            'contract-test.pdf',
            'application/pdf',
            'raw'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('contracts/contract-test.pdf', $result['public_id']);
        $this->assertSame(
            'https://res.cloudinary.com/demo/raw/upload/contracts/contract-test.pdf',
            $service->generateUrl($result['public_id'], 0, 0, 'raw')
        );
    }

    public function test_authenticated_image_urls_do_not_add_a_fallback_version(): void
    {
        $service = (new \ReflectionClass(CloudinaryService::class))->newInstanceWithoutConstructor();
        $cloudinaryProperty = new \ReflectionProperty(CloudinaryService::class, 'cloudinary');
        $cloudinaryProperty->setValue(
            $service,
            new Cloudinary('cloudinary://123456:example@demo')
        );

        $url = $service->generateAuthenticatedImageUrl('complaint-attachments/complaint-test');

        $this->assertNotNull($url);
        $this->assertStringStartsWith('https://res.cloudinary.com/demo/image/authenticated/s--', $url);
        $this->assertDoesNotMatchRegularExpression('#/v1/#', $url);
        $this->assertStringContainsString('/complaint-attachments/complaint-test', $url);
    }

    public function test_generate_url_preserves_a_complete_cloudinary_secure_url(): void
    {
        $service = (new \ReflectionClass(CloudinaryService::class))->newInstanceWithoutConstructor();
        $secureUrl = 'https://res.cloudinary.com/demo/image/upload/v123/example.png';

        $this->assertSame($secureUrl, $service->generateUrl($secureUrl));
    }

    public function test_upload_rejects_invalid_cloudinary_metadata(): void
    {
        $uploadApi = \Mockery::mock(UploadApi::class);
        $cloudinary = \Mockery::mock(Cloudinary::class);
        $cloudinary->shouldReceive('uploadApi')->once()->andReturn($uploadApi);
        $uploadApi->shouldReceive('upload')->once()->andReturn(new ApiResponse([
            'secure_url' => 'https://example.test/image.png',
            'public_id' => 'complaint-attachments/test',
            'resource_type' => 'image',
        ], []));

        $service = (new \ReflectionClass(CloudinaryService::class))->newInstanceWithoutConstructor();
        $cloudinaryProperty = new \ReflectionProperty(CloudinaryService::class, 'cloudinary');
        $cloudinaryProperty->setValue($service, $cloudinary);

        $result = $service->uploadFile(
            UploadedFile::fake()->createWithContent('complaint.png', 'image content'),
            'complaint-attachments'
        );

        $this->assertFalse($result['success']);
        $this->assertSame('Cloudinary returned an invalid image reference.', $result['message']);
    }

    public function test_upload_error_identifies_rejected_api_key_without_exposing_it(): void
    {
        $uploadApi = \Mockery::mock(UploadApi::class);
        $cloudinary = \Mockery::mock(Cloudinary::class);
        $cloudinary->shouldReceive('uploadApi')->once()->andReturn($uploadApi);
        $uploadApi->shouldReceive('upload')->once()->andThrow(
            new \RuntimeException('Invalid api_key <sensitive-test-key>')
        );

        $service = (new \ReflectionClass(CloudinaryService::class))->newInstanceWithoutConstructor();
        $cloudinaryProperty = new \ReflectionProperty(CloudinaryService::class, 'cloudinary');
        $cloudinaryProperty->setValue($service, $cloudinary);

        $result = $service->uploadFile(
            UploadedFile::fake()->createWithContent('complaint.png', 'image content'),
            'complaint-attachments'
        );

        $this->assertFalse($result['success']);
        $this->assertSame(
            'Cloudinary rejected the API key. Verify it in the backend production environment.',
            $result['message']
        );
        $this->assertStringNotContainsString('sensitive-test-key', $result['message']);
    }

    public function test_upload_error_identifies_signature_rejection_without_exposing_the_signature(): void
    {
        $uploadApi = \Mockery::mock(UploadApi::class);
        $cloudinary = \Mockery::mock(Cloudinary::class);
        $cloudinary->shouldReceive('uploadApi')->once()->andReturn($uploadApi);
        $uploadApi->shouldReceive('upload')->once()->andThrow(
            new \RuntimeException('Invalid Signature <sensitive-test-signature>')
        );

        $service = (new \ReflectionClass(CloudinaryService::class))->newInstanceWithoutConstructor();
        $cloudinaryProperty = new \ReflectionProperty(CloudinaryService::class, 'cloudinary');
        $cloudinaryProperty->setValue($service, $cloudinary);

        $result = $service->uploadFile(
            UploadedFile::fake()->createWithContent('complaint.png', 'image content'),
            'complaint-attachments'
        );

        $this->assertFalse($result['success']);
        $this->assertSame(
            'Cloudinary rejected the API secret or request signature. Verify the key and secret belong to the same account.',
            $result['message']
        );
        $this->assertStringNotContainsString('sensitive-test-signature', $result['message']);
    }
}
