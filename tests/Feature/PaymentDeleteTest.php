<?php

use App\Http\Controllers\PaymentController;
use App\Models\Contract;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::dropIfExists('payments');
    Schema::dropIfExists('demand_letters');
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

    Schema::create('audit_logs', function ($table) {
        $table->id();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('action');
        $table->string('model_type');
        $table->unsignedBigInteger('model_id')->nullable();
        $table->text('description');
        $table->json('old_values')->nullable();
        $table->json('new_values')->nullable();
        $table->string('ip_address')->nullable();
        $table->string('user_agent')->nullable();
        $table->timestamps();
    });
});

it('deletes a payment row from the database', function () {
    $user = User::create([
        'name' => 'Cashier',
        'email' => 'cashier@example.com',
        'role' => 'cashier',
        'password' => bcrypt('secret'),
    ]);

    $tenant = Tenant::create([
        'user_id' => $user->id,
        'business_name' => 'Sample Tenant',
        'contact_person' => 'Contact Person',
    ]);

    $space = DB::table('rental_spaces')->insertGetId([
        'name' => 'Space 1',
        'space_code' => 'SP-1',
        'status' => 'occupied',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $contract = Contract::create([
        'contract_number' => 'CON-2026-000101',
        'tenant_id' => $tenant->id,
        'rental_space_id' => $space,
        'start_date' => now()->subMonths(2)->toDateString(),
        'end_date' => now()->addMonths(10)->toDateString(),
        'duration_months' => 12,
        'monthly_rental' => 1200,
        'deposit_amount' => 0,
        'interest_rate' => 3,
        'status' => 'active',
    ]);

    $payment = Payment::create([
        'payment_number' => 'PAY-2026-000001',
        'contract_id' => $contract->id,
        'tenant_id' => $tenant->id,
        'billing_period_start' => now()->subMonth()->toDateString(),
        'billing_period_end' => now()->toDateString(),
        'due_date' => now()->addDays(5)->toDateString(),
        'amount_due' => 1200,
        'interest_amount' => 0,
        'total_amount' => 1200,
        'amount_paid' => 0,
        'balance' => 1200,
        'payment_method' => 'cash',
        'status' => 'pending',
    ]);

    $controller = app(PaymentController::class);
    $response = $controller->destroy($payment->id);

    expect($response->getData(true)['success'])->toBeTrue()
        ->and(Payment::find($payment->id))->toBeNull();
});
