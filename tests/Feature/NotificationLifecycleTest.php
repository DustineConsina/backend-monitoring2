<?php

use App\Models\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    if (!Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    if (!Schema::hasTable('notifications')) {
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
    }
});

it('creates a notification without failing when the schema includes the dedupe key', function () {
    $notification = Notification::create([
        'user_id' => 42,
        'type' => 'contract_terminated',
        'title' => 'Contract Terminated',
        'message' => 'The contract was terminated.',
        'data' => ['contract_id' => 99],
        'is_read' => false,
        'email_sent' => false,
    ]);

    expect($notification->exists)->toBeTrue()
        ->and($notification->user_id)->toBe(42)
        ->and($notification->type)->toBe('contract_terminated')
        ->and($notification->notification_key)->not->toBeEmpty();
});

it('marks a notification as read through the put endpoint used by the frontend', function () {
    $user = \App\Models\User::factory()->create();

    $notification = Notification::create([
        'user_id' => $user->id,
        'type' => 'contract_renewed',
        'title' => 'Contract Renewed',
        'message' => 'Your contract was renewed.',
        'data' => ['contract_id' => 10],
        'is_read' => false,
        'email_sent' => false,
    ]);

    $response = $this->actingAs($user)
        ->putJson('/api/notifications/' . $notification->id . '/read');

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.is_read', true);

    $this->assertDatabaseHas('notifications', [
        'id' => $notification->id,
        'user_id' => $user->id,
        'is_read' => true,
    ]);
});
