<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\RentalSpace;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
