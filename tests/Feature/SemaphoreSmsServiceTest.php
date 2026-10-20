<?php

use App\Services\SemaphoreSmsService;
use App\Services\OverduePaymentSmsNotifier;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config()->set('services.semaphore.api_key', 'test-api-key');
    config()->set('services.semaphore.sender_name', 'PFDA');

    if (!Schema::hasTable('sms_messages')) {
        Schema::create('sms_messages', function ($table) {
            $table->id();
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->unsignedBigInteger('recipient_user_id')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->string('phone_number', 32);
            $table->text('message');
            $table->string('provider')->default('semaphore');
            $table->string('provider_message_id')->nullable()->unique();
            $table->string('status')->default('queued');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }
});

it('reads the documented Semaphore message list response', function () {
    Http::fake([
        'api.semaphore.co/api/v4/messages' => Http::response([
            ['message_id' => 12345, 'status' => 'Queued'],
        ]),
    ]);

    $messageId = app(SemaphoreSmsService::class)->send('09171234567', 'Payment reminder');

    expect($messageId)->toBe('12345');
    $this->assertDatabaseHas('sms_messages', [
        'provider_message_id' => '12345',
        'phone_number' => '09171234567',
        'message' => 'Payment reminder',
        'status' => 'Queued',
    ]);
    Http::assertSent(fn ($request) => $request['message'] === 'Payment reminder');
});

it('reads a direct message object response', function () {
    Http::fake([
        'api.semaphore.co/api/v4/messages' => Http::response([
            'message_id' => '67890',
            'status' => 'Pending',
        ]),
    ]);

    expect(app(SemaphoreSmsService::class)->send('09171234567', 'Payment reminder'))
        ->toBe('67890');
});

it('reports an ambiguous success response without suggesting a retry', function () {
    Http::fake([
        'api.semaphore.co/api/v4/messages' => Http::response([
            'status' => 'Queued',
            'message' => 'Provider response',
        ]),
    ]);

    expect(fn () => app(SemaphoreSmsService::class)->send('09171234567', 'Payment reminder'))
        ->toThrow(RuntimeException::class, 'Do not retry until the message status is checked in Semaphore.');
});

it('treats Semaphore validation errors as rejection even when returned with a successful HTTP status', function () {
    Http::fake([
        'api.semaphore.co/api/v4/messages' => Http::response([
            'apikey' => ['The apikey field is required.'],
        ], 200),
    ]);

    expect(fn () => app(SemaphoreSmsService::class)->send('09171234567', 'Payment reminder'))
        ->toThrow(RuntimeException::class, 'Semaphore rejected the request: The apikey field is required.');

    $this->assertDatabaseHas('sms_messages', [
        'phone_number' => '09171234567',
        'status' => 'failed',
        'error_message' => 'The apikey field is required.',
    ]);
});

it('stores a failed SMS provider status and its reason', function () {
    Http::fake([
        'api.semaphore.co/api/v4/messages' => Http::response([
            [
                'message_id' => 12346,
                'status' => 'Failed',
                'error' => 'The recipient number is invalid.',
            ],
        ]),
    ]);

    expect(app(SemaphoreSmsService::class)->send('09170000000', 'Payment reminder'))
        ->toBe('12346');

    $this->assertDatabaseHas('sms_messages', [
        'provider_message_id' => '12346',
        'status' => 'Failed',
        'error_message' => 'The recipient number is invalid.',
    ]);
});

it('refreshes a pending SMS to failed and saves the provider reason', function () {
    $sms = \App\Models\SmsMessage::create([
        'phone_number' => '09170000000',
        'message' => 'Payment reminder',
        'provider' => 'semaphore',
        'provider_message_id' => '12347',
        'status' => 'Pending',
    ]);

    Http::fake([
        'api.semaphore.co/api/v4/messages/12347*' => Http::response([
            'message_id' => 12347,
            'status' => 'Failed',
            'reason' => 'Recipient number is not registered.',
        ]),
    ]);

    app(SemaphoreSmsService::class)->refreshStatus($sms);

    $this->assertDatabaseHas('sms_messages', [
        'id' => $sms->id,
        'status' => 'Failed',
        'error_message' => 'Recipient number is not registered.',
    ]);
});

it('sends an overdue payment SMS immediately when a payment is marked overdue', function () {
    $payment = new Payment([
        'payment_number' => 'PAY-2026-000001',
        'billing_period_start' => '2026-09-01',
        'due_date' => '2026-10-01',
        'balance' => 1200,
        'status' => 'overdue',
    ]);
    $payment->id = 123;

    $tenant = new Tenant([
        'contact_number' => '09171234567',
        'contact_person' => 'Tenant Contact',
    ]);
    $tenant->setRelation('user', new User(['name' => 'Tenant User']));
    $payment->setRelation('tenant', $tenant);

    $smsService = Mockery::mock(SemaphoreSmsService::class);
    $smsService->shouldReceive('send')
        ->once()
        ->with('09171234567', Mockery::type('string'), null, 123)
        ->andReturn('message-123');
    app()->instance(SemaphoreSmsService::class, $smsService);

    app(OverduePaymentSmsNotifier::class)->sendIfDue($payment);
});

it('retries an overdue SMS only after its API key configuration failure', function () {
    \App\Models\SmsMessage::create([
        'payment_id' => 124,
        'phone_number' => '09171234567',
        'message' => 'Previous overdue reminder',
        'provider' => 'semaphore',
        'status' => 'failed',
        'error_message' => 'SEMAPHORE_API_KEY is not configured.',
    ]);

    $payment = new Payment([
        'payment_number' => 'PAY-2026-000002',
        'billing_period_start' => '2026-09-01',
        'due_date' => '2026-10-01',
        'balance' => 1200,
        'status' => 'overdue',
    ]);
    $payment->id = 124;
    $tenant = new Tenant([
        'contact_number' => '09171234567',
        'contact_person' => 'Tenant Contact',
    ]);
    $tenant->setRelation('user', new User(['name' => 'Tenant User']));
    $payment->setRelation('tenant', $tenant);

    $smsService = Mockery::mock(SemaphoreSmsService::class);
    $smsService->shouldReceive('send')
        ->once()
        ->with('09171234567', Mockery::type('string'), 42, 124)
        ->andReturn('message-124');
    app()->instance(SemaphoreSmsService::class, $smsService);

    expect(app(OverduePaymentSmsNotifier::class)->retryConfigurationFailure($payment, 42))
        ->toBe('message-124');
});

it('does not retry an overdue SMS for unrelated or ambiguous failures', function () {
    \App\Models\SmsMessage::create([
        'payment_id' => 125,
        'phone_number' => '09171234567',
        'message' => 'Previous overdue reminder',
        'provider' => 'semaphore',
        'status' => 'unknown',
        'error_message' => 'Semaphore delivery status is unknown.',
    ]);

    $payment = new Payment([
        'payment_number' => 'PAY-2026-000003',
        'billing_period_start' => '2026-09-01',
        'due_date' => '2026-10-01',
        'balance' => 1200,
        'status' => 'overdue',
    ]);
    $payment->id = 125;

    expect(fn () => app(OverduePaymentSmsNotifier::class)->retryConfigurationFailure($payment, 42))
        ->toThrow(RuntimeException::class, 'missing-API-key SMS failure');
});
