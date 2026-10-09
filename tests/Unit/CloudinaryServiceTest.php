<?php

namespace Tests\Unit;

use App\Services\CloudinaryService;
use Cloudinary\Api\ApiResponse;
use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Cloudinary;
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
}
