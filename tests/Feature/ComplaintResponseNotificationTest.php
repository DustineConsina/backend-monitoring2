<?php

use App\Mail\ComplaintResponseNotification;
use App\Models\Complaint;
use App\Models\ComplaintReply;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CloudinaryService;
use App\Services\TenantQrToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function createComplaintResponseFixture(?string $email = 'tenant@example.com'): array
{
    $admin = User::create([
        'name' => 'Admin User',
        'email' => 'admin@example.com',
        'password' => 'password',
        'role' => 'admin',
        'status' => 'active',
    ]);
    $tenantUser = User::create([
        'name' => 'Tenant User',
        'email' => $email,
        'password' => 'tenant-password',
        'role' => 'tenant',
        'status' => 'active',
    ]);
    $tenant = Tenant::create([
        'user_id' => $tenantUser->id,
        'tenant_code' => 'TEN-TEST-0001',
        'business_name' => 'Tenant Business',
        'contact_person' => 'Tenant Person',
        'contact_number' => '09123456789',
        'status' => 'active',
    ]);
    $complaint = $tenant->complaints()->create([
        'category' => 'other',
        'subject' => 'Stall maintenance request',
        'message' => 'Please inspect the stall door because it does not close properly.',
        'status' => 'pending',
        'admin_read' => false,
    ]);

    return [$admin, $tenantUser, $tenant, $complaint];
}

function mockCloudinaryComplaintImages(): void
{
    $uploadNumber = 0;
    $cloudinary = Mockery::mock(CloudinaryService::class);
    $cloudinary->shouldReceive('uploadPrivateImageFile')
        ->andReturnUsing(function () use (&$uploadNumber) {
            $uploadNumber++;

            return [
                'success' => true,
                'public_id' => "complaint-attachments/test-{$uploadNumber}",
            ];
        });
    $cloudinary->shouldReceive('generateAuthenticatedImageUrl')
        ->andReturn('https://res.cloudinary.com/test/image/authenticated/complaint.png');
    $cloudinary->shouldReceive('deleteFile')->andReturn(true);
    app()->instance(CloudinaryService::class, $cloudinary);

    $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/Jx8AAAAASUVORK5CYII=', true);
    Http::fake([
        'https://res.cloudinary.com/*' => Http::response($image, 200, ['Content-Type' => 'image/png']),
    ]);
}

it('saves an admin response before sending a minimal tenant email and shows it in the QR complaint flow', function () {
    config(['app.frontend_url' => 'https://contracts.example.test']);
    [$admin, $tenantUser, $tenant, $complaint] = createComplaintResponseFixture();
    $requestKey = (string) \Illuminate\Support\Str::uuid();
    $responseText = 'Our maintenance team will inspect the door this week.';
    $acceptedMessage = \Mockery::mock(\Illuminate\Mail\SentMessage::class);
    $acceptedMessage->shouldReceive('getMessageId')->once()->andReturn('<provider-message-123@example.test>');
    $renderer = app('mailer');
    app()->instance('mailer', $renderer);
    $pendingMail = \Mockery::mock(\Illuminate\Mail\PendingMail::class);
    $capturedMail = null;
    $pendingMail->shouldReceive('send')
        ->once()
        ->with(\Mockery::on(function ($mail) use (&$capturedMail, $requestKey, $tenantUser) {
            $capturedMail = $mail;
            $mail->to($tenantUser->email);
            return $mail instanceof ComplaintResponseNotification
                && ComplaintReply::where('request_key', $requestKey)->exists();
        }))
        ->andReturn($acceptedMessage);
    Mail::shouldReceive('mailer')->andReturn($renderer);
    Mail::shouldReceive('to')->once()->with($tenantUser->email)->andReturn($pendingMail);

    $this->actingAs($admin, 'sanctum')
        ->putJson("/api/complaints/{$complaint->id}", [
            'status' => 'in_progress',
            'reply' => $responseText,
            'request_key' => $requestKey,
        ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('notification_accepted', true)
        ->assertJsonPath('notification_sent', true);

    $reply = ComplaintReply::where('request_key', $requestKey)->firstOrFail();
    expect($reply->complaint_id)->toBe($complaint->id)
        ->and($reply->sender_id)->toBe($admin->id)
        ->and($reply->email_sent_at)->not->toBeNull();
    expect($complaint->fresh()->status)->toBe('in_progress');

    expect($capturedMail)->toBeInstanceOf(ComplaintResponseNotification::class);
    $rendered = $capturedMail->render();
    expect($capturedMail->hasTo($tenantUser->email))->toBeTrue()
        ->and($capturedMail->subject)->toBe('New Response to Your Complaint – Contract Management System')
        ->and($rendered)->toContain('https://contracts.example.test/tenant-access')
        ->and($rendered)->not->toContain($responseText)
        ->and($rendered)->not->toContain($complaint->message);

    $tenant->forceFill([
        'qr_password_hash' => \Illuminate\Support\Facades\Hash::make('12345678'),
        'qr_access_code' => '12345678',
    ])->save();
    $qrToken = app(TenantQrToken::class)->issue($tenant);
    $this->postJson('/api/public/tenant-scan', [
        'token' => $qrToken,
        'access_code' => '12345678',
    ])
        ->assertOk()
        ->assertJsonPath('data.complaints.0.subject', 'Stall maintenance request')
        ->assertJsonPath('data.complaints.0.replies.0.message', $responseText);

    for ($index = 1; $index <= 6; $index++) {
        $tenant->complaints()->create([
            'category' => 'other',
            'subject' => "Already read request {$index}",
            'message' => 'This request is already read by the administration.',
            'status' => 'pending',
            'admin_read' => true,
        ]);
    }

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/complaints/recent')
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('data.0.subject', 'Stall maintenance request')
        ->assertJsonPath('data.0.tenant.contact_person', 'Tenant Person')
        ->assertJsonPath('data.0.tenant.contracts', []);

    $this->actingAs($admin, 'sanctum')
        ->putJson("/api/complaints/{$complaint->id}", [
            'status' => 'in_progress',
            'reply' => $responseText,
            'request_key' => $requestKey,
        ])
        ->assertOk();

    $pendingMail->shouldHaveReceived('send')->once();
    expect(ComplaintReply::where('request_key', $requestKey)->count())->toBe(1);
});

it('keeps the saved response when the registered email is invalid', function () {
    Mail::fake();
    [$admin, , , $complaint] = createComplaintResponseFixture('not-an-email');

    $this->actingAs($admin, 'sanctum')
        ->putJson("/api/complaints/{$complaint->id}", [
            'status' => 'pending',
            'reply' => 'Please visit the office during business hours.',
            'request_key' => (string) \Illuminate\Support\Str::uuid(),
        ])
        ->assertOk()
        ->assertJsonPath('notification_sent', false)
        ->assertJsonPath('notification_message', 'Response saved, but notification could not be sent because the tenant has no valid registered email address.');

    expect(ComplaintReply::where('complaint_id', $complaint->id)->count())->toBe(1);
    Mail::assertNothingSent();
});

it('keeps the saved response and reports an email transport failure', function () {
    config(['app.frontend_url' => 'https://contracts.example.test']);
    [$admin, $tenantUser, , $complaint] = createComplaintResponseFixture();
    $pendingMail = \Mockery::mock(\Illuminate\Mail\PendingMail::class);
    $pendingMail->shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP unavailable'));
    Mail::shouldReceive('to')->once()->with($tenantUser->email)->andReturn($pendingMail);

    $this->actingAs($admin, 'sanctum')
        ->putJson("/api/complaints/{$complaint->id}", [
            'status' => 'in_progress',
            'reply' => 'We have received your request and will follow up shortly.',
            'request_key' => (string) \Illuminate\Support\Str::uuid(),
        ])
        ->assertOk()
        ->assertJsonPath('notification_sent', false)
        ->assertJsonPath('notification_message', 'Response saved, but the notification email could not be sent. Check the email configuration and try again.');

    expect(ComplaintReply::where('complaint_id', $complaint->id)->count())->toBe(1);
});

it('does not report an email as accepted without a transport receipt', function () {
    Mail::fake();
    config(['app.frontend_url' => 'https://contracts.example.test']);
    [$admin, , , $complaint] = createComplaintResponseFixture();
    $requestKey = (string) \Illuminate\Support\Str::uuid();

    $this->actingAs($admin, 'sanctum')
        ->putJson("/api/complaints/{$complaint->id}", [
            'status' => 'in_progress',
            'reply' => 'We have received your request.',
            'request_key' => $requestKey,
        ])
        ->assertOk()
        ->assertJsonPath('notification_accepted', false)
        ->assertJsonPath('notification_sent', false);

    $reply = ComplaintReply::where('request_key', $requestKey)->firstOrFail();
    expect($reply->email_sent_at)->toBeNull();
    Mail::assertSent(ComplaintResponseNotification::class, 1);
});

it('rejects whitespace-only admin responses', function () {
    Mail::fake();
    [$admin, , , $complaint] = createComplaintResponseFixture();

    $this->actingAs($admin, 'sanctum')
        ->putJson("/api/complaints/{$complaint->id}", [
            'status' => 'pending',
            'reply' => '   ',
            'request_key' => (string) \Illuminate\Support\Str::uuid(),
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Enter a response before sending it.');

    expect(ComplaintReply::where('complaint_id', $complaint->id)->count())->toBe(0);
    Mail::assertNothingSent();
});

it('allows tenants and administrators to upload private complaint images', function () {
    mockCloudinaryComplaintImages();
    Mail::fake();
    config(['app.frontend_url' => 'https://contracts.example.test']);
    [$admin, , $tenant] = createComplaintResponseFixture();
    $fakeImage = static fn (string $name) => UploadedFile::fake()->createWithContent(
        $name,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/Jx8AAAAASUVORK5CYII=', true),
    );
    $tenant->forceFill([
        'qr_password_hash' => \Illuminate\Support\Facades\Hash::make('12345678'),
        'qr_access_code' => '12345678',
    ])->save();
    $qrToken = app(TenantQrToken::class)->issue($tenant);

    $this->post('/api/public/tenant-scan/complaints', [
        'token' => $qrToken,
        'access_code' => '12345678',
        'category' => 'other',
        'subject' => 'Photo of damaged stall',
        'message' => 'The stall shutter has visible damage near the lock.',
        'image' => $fakeImage('damage.png'),
    ])
        ->assertCreated()
        ->assertJsonPath('data.has_image', true);

    $savedComplaint = Complaint::where('subject', 'Photo of damaged stall')->firstOrFail();
    expect($savedComplaint->image_path)->toStartWith('cloudinary:complaint-attachments/');

    $requestKey = (string) \Illuminate\Support\Str::uuid();
    $this->actingAs($admin, 'sanctum')
        ->post("/api/complaints/{$savedComplaint->id}", [
            '_method' => 'PUT',
            'status' => 'in_progress',
            'reply' => 'We have forwarded the photo to maintenance.',
            'request_key' => $requestKey,
            'reply_image' => $fakeImage('repair.png'),
        ])
        ->assertOk()
        ->assertJsonPath('data.replies.0.has_image', true);

    $reply = ComplaintReply::where('request_key', $requestKey)->firstOrFail();
    expect($reply->image_path)->toStartWith('cloudinary:complaint-attachments/');
    $this->get("/api/complaints/{$savedComplaint->id}/image")->assertOk();
    $this->get("/api/complaints/{$savedComplaint->id}/replies/{$reply->id}/image")->assertOk();

    $this->postJson('/api/public/tenant-scan', [
        'token' => $qrToken,
        'access_code' => '12345678',
    ])
        ->assertOk()
        ->assertJsonPath('data.complaints.0.subject', 'Photo of damaged stall')
        ->assertJsonPath('data.complaints.0.has_image', true)
        ->assertJsonPath('data.complaints.0.replies.0.has_image', true)
        ->assertJsonMissingPath('data.complaints.0.image_path');

    $this->postJson('/api/public/tenant-scan/complaints/image', [
        'token' => $qrToken,
        'access_code' => '12345678',
        'complaint_id' => $savedComplaint->id,
    ])->assertOk();

    $this->postJson('/api/public/tenant-scan/complaints/image', [
        'token' => $qrToken,
        'access_code' => '12345678',
        'complaint_id' => $savedComplaint->id,
        'reply_id' => $reply->id,
    ])->assertOk();

    $this->postJson('/api/public/tenant-scan/complaints/image', [
        'token' => $qrToken,
        'access_code' => '87654321',
        'complaint_id' => $savedComplaint->id,
    ])->assertForbidden();
});

it('loads real complaints for admins and permits only the QR tenant to reply chronologically', function () {
    mockCloudinaryComplaintImages();
    [$admin, $tenantUser, $tenant, $complaint] = createComplaintResponseFixture();
    $tenant->forceFill([
        'qr_password_hash' => \Illuminate\Support\Facades\Hash::make('12345678'),
        'qr_access_code' => '12345678',
    ])->save();
    $qrToken = app(TenantQrToken::class)->issue($tenant);
    $adminReply = $complaint->replies()->create([
        'sender_id' => $admin->id,
        'message' => 'Administration has received this request.',
    ]);

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/complaints')
        ->assertOk()
        ->assertJsonPath('data.0.id', $complaint->id)
        ->assertJsonPath('data.0.replies.0.id', $adminReply->id);

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/Jx8AAAAASUVORK5CYII=', true);
    $this->post('/api/public/tenant-scan/complaints/replies', [
        'token' => $qrToken,
        'access_code' => '12345678',
        'complaint_id' => $complaint->id,
        'message' => 'Here is another photo of the problem.',
        'image' => UploadedFile::fake()->createWithContent('follow-up.png', $png),
    ])
        ->assertCreated()
        ->assertJsonPath('data.sender.id', $tenantUser->id)
        ->assertJsonPath('data.sender.role', 'tenant')
        ->assertJsonPath('data.has_image', true);

    $tenantReply = ComplaintReply::where('complaint_id', $complaint->id)->where('sender_id', $tenantUser->id)->firstOrFail();
    expect($tenantReply->image_path)->toStartWith('cloudinary:complaint-attachments/');
    $this->postJson('/api/public/tenant-scan/complaints/image', [
        'token' => $qrToken,
        'access_code' => '12345678',
        'complaint_id' => $complaint->id,
        'reply_id' => $tenantReply->id,
    ])->assertOk();

    $this->postJson('/api/public/tenant-scan', [
        'token' => $qrToken,
        'access_code' => '12345678',
    ])
        ->assertOk()
        ->assertJsonPath('data.complaints.0.replies.0.message', 'Administration has received this request.')
        ->assertJsonPath('data.complaints.0.replies.1.message', 'Here is another photo of the problem.');

    $otherUser = User::create([
        'name' => 'Other Tenant',
        'email' => 'other-tenant@example.com',
        'password' => 'tenant-password',
        'role' => 'tenant',
        'status' => 'active',
    ]);
    $otherTenant = Tenant::create([
        'user_id' => $otherUser->id,
        'tenant_code' => 'TEN-TEST-0002',
        'business_name' => 'Other Tenant Business',
        'contact_person' => 'Other Tenant',
        'contact_number' => '09123456780',
        'status' => 'active',
    ]);
    $otherComplaint = $otherTenant->complaints()->create([
        'category' => 'other',
        'subject' => 'Another tenant request',
        'message' => 'This complaint must not be accessible to a different tenant.',
        'status' => 'pending',
    ]);

    $this->postJson('/api/public/tenant-scan/complaints/replies', [
        'token' => $qrToken,
        'access_code' => '12345678',
        'complaint_id' => $otherComplaint->id,
        'message' => 'Unauthorized reply attempt.',
    ])->assertNotFound();

    $this->postJson('/api/public/tenant-scan/complaints/replies', [
        'token' => $qrToken,
        'access_code' => '87654321',
        'complaint_id' => $complaint->id,
        'message' => 'Wrong QR password.',
    ])->assertForbidden();

    $complaint->forceFill(['status' => 'resolved'])->save();
    $this->postJson('/api/public/tenant-scan/complaints/replies', [
        'token' => $qrToken,
        'access_code' => '12345678',
        'complaint_id' => $complaint->id,
        'message' => 'Reply to resolved complaint.',
    ])->assertUnprocessable();
});

it('serves legacy public-disk complaint images after moving new uploads to S3', function () {
    Storage::fake('s3');
    Storage::fake('public');
    config(['filesystems.complaint_attachments' => 's3']);
    [$admin, , , $complaint] = createComplaintResponseFixture();
    $imagePath = 'complaints/legacy-image.png';
    $imageContent = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/Jx8AAAAASUVORK5CYII=', true);
    Storage::disk('public')->put($imagePath, $imageContent);
    $complaint->forceFill(['image_path' => $imagePath])->save();

    $this->actingAs($admin, 'sanctum')
        ->get("/api/complaints/{$complaint->id}/image")
        ->assertOk()
        ->assertHeader('content-type', 'image/png');
});
