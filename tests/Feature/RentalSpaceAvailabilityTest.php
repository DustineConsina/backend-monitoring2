<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\RentalSpace;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CloudinaryService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RentalSpaceAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_contract_keeps_space_unavailable_for_new_contracts()
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create(['user_id' => $user->id]);
        $space = RentalSpace::factory()->create(['status' => 'available']);

        Contract::factory()->create([
            'tenant_id' => $tenant->id,
            'rental_space_id' => $space->id,
            'status' => 'pending',
        ]);

        $this->assertFalse($space->fresh()->isAvailable());
        $this->assertEquals(0, RentalSpace::available()->count());
    }

    public function test_for_renewal_contract_is_excluded_from_available_spaces()
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create(['user_id' => $user->id]);
        $space = RentalSpace::factory()->create(['status' => 'occupied']);

        Contract::factory()->create([
            'tenant_id' => $tenant->id,
            'rental_space_id' => $space->id,
            'status' => 'for_renewal',
        ]);

        $this->assertFalse($space->fresh()->isAvailable());
        $this->assertEquals(0, RentalSpace::available()->count());
    }

    public function test_inactive_space_remains_inactive_and_unavailable_after_occupancy_sync()
    {
        $spaceId = DB::table('rental_spaces')->insertGetId([
            'space_code' => 'INACTIVE-TEST',
            'space_type' => 'food_stall',
            'name' => 'Inactive test space',
            'size_sqm' => 10,
            'base_rental_rate' => 100,
            'status' => 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $space = RentalSpace::findOrFail($spaceId);

        RentalSpace::syncOccupancyStatuses();

        $this->assertSame('inactive', $space->fresh()->status);
        $this->assertFalse($space->fresh()->isAvailable());
        $this->assertEquals(0, RentalSpace::available()->count());
    }

    public function test_rental_space_can_be_marked_inactive_and_remains_in_the_listing()
    {
        $this->withoutMiddleware();

        $spaceId = DB::table('rental_spaces')->insertGetId([
            'space_code' => 'INACTIVE-API-TEST',
            'space_type' => 'food_stall',
            'name' => 'Inactive API test space',
            'size_sqm' => 10,
            'base_rental_rate' => 100,
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->putJson("/api/rental-spaces/{$spaceId}", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive');

        $this->getJson('/api/rental-spaces?include_all=1')
            ->assertOk()
            ->assertJsonFragment(['status' => 'inactive']);
    }

    public function test_rental_space_images_are_uploaded_to_cloudinary_and_return_a_display_url()
    {
        $this->withoutMiddleware();

        $imageUrl = 'https://res.cloudinary.com/example/image/upload/rental-spaces/test-image';
        $cloudinary = \Mockery::mock(CloudinaryService::class);
        $cloudinary->shouldReceive('uploadFile')
            ->once()
            ->with(\Mockery::type(UploadedFile::class), 'rental-spaces', \Mockery::type('string'))
            ->andReturn([
                'success' => true,
                'url' => $imageUrl,
                'public_id' => 'rental-spaces/test-image',
                'resource_type' => 'image',
            ]);
        $cloudinary->shouldReceive('generateUrl')
            ->twice()
            ->with('rental-spaces/test-image', 1200, 900)
            ->andReturn($imageUrl);
        $this->app->instance(CloudinaryService::class, $cloudinary);

        $imageContent = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l7sAAAAASUVORK5CYII=', true);
        $this->assertNotFalse($imageContent);

        $this->post('/api/rental-spaces', [
            'space_type' => 'food_stall',
            'name' => 'Cloudinary image test',
            'size_sqm' => 10,
            'base_rental_rate' => 100,
            'map_image' => UploadedFile::fake()->createWithContent('space.png', $imageContent),
        ])->assertCreated()
            ->assertJsonPath('data.map_image', 'rental-spaces/test-image')
            ->assertJsonPath('data.map_image_url', $imageUrl);

        $this->getJson('/api/rental-spaces?include_all=1')
            ->assertOk()
            ->assertJsonFragment([
                'map_image' => 'rental-spaces/test-image',
                'map_image_url' => $imageUrl,
            ]);
    }

    public function test_legacy_local_rental_space_image_can_be_migrated_without_deleting_the_original()
    {
        Storage::fake('public');
        Storage::disk('public')->put('rental_spaces/legacy.png', 'image-content');
        $spaceId = DB::table('rental_spaces')->insertGetId([
            'space_code' => 'LEGACY-IMAGE-TEST',
            'space_type' => 'food_stall',
            'name' => 'Legacy image test space',
            'size_sqm' => 10,
            'base_rental_rate' => 100,
            'map_image' => 'rental_spaces/legacy.png',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cloudinary = \Mockery::mock(CloudinaryService::class);
        $cloudinary->shouldReceive('uploadFile')
            ->once()
            ->with(\Mockery::type(UploadedFile::class), 'rental-spaces', \Mockery::type('string'), 'image')
            ->andReturn([
                'success' => true,
                'public_id' => 'rental-spaces/legacy-imported',
                'resource_type' => 'image',
            ]);
        $this->app->instance(CloudinaryService::class, $cloudinary);

        $this->assertSame(Command::SUCCESS, Artisan::call('assets:migrate-local-to-cloudinary'));
        $this->assertSame('rental-spaces/legacy-imported', RentalSpace::findOrFail($spaceId)->map_image);
        Storage::disk('public')->assertExists('rental_spaces/legacy.png');
    }

    public function test_expired_contract_makes_space_available()
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create(['user_id' => $user->id]);
        $space = RentalSpace::factory()->create(['status' => 'occupied']);

        Contract::factory()->create([
            'tenant_id' => $tenant->id,
            'rental_space_id' => $space->id,
            'start_date' => now()->subMonths(3),
            'end_date' => now()->subDay(),
            'status' => 'expired',
        ]);

        Contract::updateStatuses();
        RentalSpace::syncOccupancyStatuses();

        $this->assertTrue($space->fresh()->isAvailable());
        $this->assertEquals(1, RentalSpace::available()->count());
    }

    public function test_terminated_contract_makes_space_available_and_deleted_contract_also_makes_space_available()
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create(['user_id' => $user->id]);
        $space = RentalSpace::factory()->create(['status' => 'occupied']);

        $terminatedContract = Contract::factory()->create([
            'tenant_id' => $tenant->id,
            'rental_space_id' => $space->id,
            'start_date' => now()->subMonths(3),
            'end_date' => now()->addMonths(2),
            'status' => 'terminated',
        ]);

        $terminatedContract->status = 'terminated';
        $terminatedContract->save();

        Contract::updateStatuses();
        RentalSpace::syncOccupancyStatuses();

        $this->assertTrue($space->fresh()->isAvailable());

        $deletedContract = Contract::factory()->create([
            'tenant_id' => $tenant->id,
            'rental_space_id' => $space->id,
            'start_date' => now()->subMonths(2),
            'end_date' => now()->addMonths(4),
            'status' => 'active',
        ]);

        $deletedContract->delete();
        Contract::updateStatuses();
        RentalSpace::syncOccupancyStatuses();

        $this->assertTrue($space->fresh()->isAvailable());
    }
}
