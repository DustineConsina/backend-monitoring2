<?php

use App\Http\Controllers\ContractController;
use App\Models\Contract;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::dropIfExists('notifications');
    Schema::dropIfExists('contracts');
    Schema::dropIfExists('tenants');
    Schema::dropIfExists('rental_spaces');
    Schema::dropIfExists('users');

    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('role')->default('tenant');
        $table->string('password');
        $table->timestamps();
    });

    Schema::create('rental_spaces', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('space_code')->nullable();
        $table->string('status')->default('available');
        $table->timestamps();
    });

    Schema::create('tenants', function ($table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('business_name')->nullable();
        $table->string('contact_person')->nullable();
        $table->timestamps();
    });

    Schema::create('contracts', function ($table) {
        $table->id();
        $table->string('contract_number')->unique();
        $table->unsignedBigInteger('tenant_id');
        $table->unsignedBigInteger('rental_space_id');
        $table->date('start_date');
        $table->date('end_date');
        $table->integer('duration_months');
        $table->decimal('monthly_rental', 10, 2)->default(0);
        $table->decimal('deposit_amount', 10, 2)->default(0);
        $table->decimal('interest_rate', 5, 2)->default(0);
        $table->text('terms_conditions')->nullable();
        $table->string('contract_file')->nullable();
        $table->enum('status', ['active', 'expired', 'terminated', 'pending', 'for_renewal', 'renewed'])->default('pending');
        $table->timestamps();
        $table->softDeletes();
    });

    Schema::create('notifications', function ($table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('type');
        $table->string('title');
        $table->text('message');
        $table->json('data')->nullable();
        $table->boolean('is_read')->default(false);
        $table->boolean('email_sent')->default(false);
        $table->timestamp('read_at')->nullable();
        $table->timestamp('email_sent_at')->nullable();
        $table->string('notification_key')->nullable();
        $table->timestamps();
    });

    Schema::create('audit_logs', function ($table) {
        $table->id();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('action');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id');
        $table->string('description');
        $table->json('old_values')->nullable();
        $table->json('new_values')->nullable();
        $table->string('ip_address')->nullable();
        $table->string('user_agent')->nullable();
        $table->timestamps();
    });
});

it('updates the current contract and keeps it in renewed status immediately after renewal', function () {
    $tenantUser = DB::table('users')->insertGetId([
        'name' => 'Tenant User',
        'email' => 'tenant@example.com',
        'role' => 'tenant',
        'password' => bcrypt('secret'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $tenantId = DB::table('tenants')->insertGetId([
        'user_id' => $tenantUser,
        'business_name' => 'Sample Tenant',
        'contact_person' => 'Tenant Person',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $spaceId = DB::table('rental_spaces')->insertGetId([
        'name' => 'Room 1',
        'space_code' => 'R1',
        'status' => 'occupied',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $oldContract = Contract::create([
        'contract_number' => 'CON-2026-000001',
        'tenant_id' => $tenantId,
        'rental_space_id' => $spaceId,
        'start_date' => now()->subMonths(6)->toDateString(),
        'end_date' => now()->addDays(15)->toDateString(),
        'duration_months' => 6,
        'monthly_rental' => 2500,
        'deposit_amount' => 0,
        'interest_rate' => 3,
        'terms_conditions' => 'original terms',
        'status' => 'for_renewal',
    ]);

    $adminUserId = DB::table('users')->insertGetId([
        'name' => 'Admin User',
        'email' => 'admin@example.com',
        'role' => 'admin',
        'password' => bcrypt('secret'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('notifications')->insert([
        'user_id' => $adminUserId,
        'type' => 'system',
        'title' => 'seed',
        'message' => 'seed',
        'data' => json_encode([]),
        'is_read' => false,
        'email_sent' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $controller = app(ContractController::class);
    $request = new Request([
        'duration_months' => 12,
        'monthly_rental' => 2800,
    ]);

    $response = $controller->renew($request, $oldContract->id);

    $oldContract->refresh();

    expect($response->getStatusCode())->toBe(200)
        ->and($oldContract->id)->toBe($oldContract->id)
        ->and($oldContract->status)->toBe('renewed')
        ->and($oldContract->monthly_rental)->toBe('2800.00')
        ->and(Contract::where('tenant_id', $tenantId)->count())->toBe(1);
});

it('hard-deletes the contract and removes related payments from the database', function () {
    $userId = DB::table('users')->insertGetId([
        'name' => 'Tenant User',
        'email' => 'delete-tenant@example.com',
        'role' => 'tenant',
        'password' => bcrypt('secret'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $tenantId = DB::table('tenants')->insertGetId([
        'user_id' => $userId,
        'business_name' => 'Delete Tenant',
        'contact_person' => 'Delete Person',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $spaceId = DB::table('rental_spaces')->insertGetId([
        'name' => 'Room Delete',
        'space_code' => 'RD1',
        'status' => 'occupied',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $contract = Contract::create([
        'contract_number' => 'CON-2026-000999',
        'tenant_id' => $tenantId,
        'rental_space_id' => $spaceId,
        'start_date' => now()->subMonths(2)->toDateString(),
        'end_date' => now()->addMonths(10)->toDateString(),
        'duration_months' => 12,
        'monthly_rental' => 2600,
        'deposit_amount' => 0,
        'interest_rate' => 3,
        'terms_conditions' => 'delete terms',
        'status' => 'active',
    ]);

    Schema::create('payments', function ($table) {
        $table->id();
        $table->string('payment_number')->unique();
        $table->unsignedBigInteger('contract_id');
        $table->unsignedBigInteger('tenant_id');
        $table->date('billing_period_start');
        $table->date('billing_period_end');
        $table->date('due_date');
        $table->decimal('amount_due', 10, 2);
        $table->decimal('interest_amount', 10, 2)->default(0);
        $table->decimal('total_amount', 10, 2);
        $table->decimal('amount_paid', 10, 2)->default(0);
        $table->decimal('balance', 10, 2);
        $table->date('payment_date')->nullable();
        $table->string('payment_method')->nullable();
        $table->string('reference_number')->nullable();
        $table->text('remarks')->nullable();
        $table->string('status')->default('pending');
        $table->timestamps();
    });

    DB::table('payments')->insert([
        'payment_number' => 'PAY-2026-001',
        'contract_id' => $contract->id,
        'tenant_id' => $tenantId,
        'billing_period_start' => now()->subMonths(1)->toDateString(),
        'billing_period_end' => now()->toDateString(),
        'due_date' => now()->addDays(5)->toDateString(),
        'amount_due' => 2600,
        'interest_amount' => 0,
        'total_amount' => 2600,
        'amount_paid' => 0,
        'balance' => 2600,
        'payment_date' => null,
        'payment_method' => 'cash',
        'reference_number' => null,
        'remarks' => 'test payment',
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $controller = app(ContractController::class);
    $response = $controller->destroy($contract->id);

    expect($response->getData(true)['success'])->toBeTrue()
        ->and(Contract::find($contract->id))->toBeNull()
        ->and(DB::table('payments')->where('contract_id', $contract->id)->count())->toBe(0)
        ->and(DB::table('rental_spaces')->where('id', $spaceId)->value('status'))->toBe('available');
});

it('moves a renewed contract back to active after the 5-day grace period', function () {
    $tenantUser = DB::table('users')->insertGetId([
        'name' => 'Tenant User',
        'email' => 'tenant3@example.com',
        'role' => 'tenant',
        'password' => bcrypt('secret'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $tenantId = DB::table('tenants')->insertGetId([
        'user_id' => $tenantUser,
        'business_name' => 'Sample Tenant 3',
        'contact_person' => 'Tenant Person 3',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $spaceId = DB::table('rental_spaces')->insertGetId([
        'name' => 'Room 3',
        'space_code' => 'R3',
        'status' => 'occupied',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $contract = Contract::create([
        'contract_number' => 'CON-2026-000003',
        'tenant_id' => $tenantId,
        'rental_space_id' => $spaceId,
        'start_date' => now()->subDays(6)->toDateString(),
        'end_date' => now()->addMonths(11)->toDateString(),
        'duration_months' => 12,
        'monthly_rental' => 3200,
        'deposit_amount' => 0,
        'interest_rate' => 3,
        'terms_conditions' => 'renewed terms',
        'status' => 'renewed',
    ]);

    Contract::updateStatuses();
    $contract->refresh();

    expect($contract->status)->toBe('active');
});

it('does not reclassify a renewed contract as expired during the global status sync', function () {
    $tenantUser = DB::table('users')->insertGetId([
        'name' => 'Tenant User',
        'email' => 'tenant2@example.com',
        'role' => 'tenant',
        'password' => bcrypt('secret'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $tenantId = DB::table('tenants')->insertGetId([
        'user_id' => $tenantUser,
        'business_name' => 'Sample Tenant 2',
        'contact_person' => 'Tenant Person 2',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $spaceId = DB::table('rental_spaces')->insertGetId([
        'name' => 'Room 2',
        'space_code' => 'R2',
        'status' => 'occupied',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $contract = Contract::create([
        'contract_number' => 'CON-2026-000002',
        'tenant_id' => $tenantId,
        'rental_space_id' => $spaceId,
        'start_date' => now()->subDays(1)->toDateString(),
        'end_date' => now()->addMonths(11)->toDateString(),
        'duration_months' => 12,
        'monthly_rental' => 3000,
        'deposit_amount' => 0,
        'interest_rate' => 3,
        'terms_conditions' => 'renewed terms',
        'status' => 'renewed',
    ]);

    Contract::updateStatuses();

    $contract->refresh();

    expect($contract->status)->toBe('renewed');
});
