<?php

use App\Services\SmsMessageTemplates;

it('formats the requested overdue rent reminder with tenant, amount, month, and due date', function () {
    $message = SmsMessageTemplates::overduePayment('Eralyn Forte', 'PAY-2026-000009', 3211, 'September 2026', 'September 12, 2026');

    expect($message)->toBe(
        'PFDA Bulan: Hi Eralyn Forte, your rent of P3,211.00 for September 2026 was due on September 12, 2026. '
        . 'Please pay as soon as possible. Ignore if already paid. Thank you.'
    )->and(str_starts_with($message, 'TEST'))->toBeFalse();
});

it('formats the requested contract renewal reminder with the tenant and stall number', function () {
    expect(SmsMessageTemplates::contractRenewal('Eralyn Forte', 'FS-001', 'December 5, 2026'))
        ->toBe('PFDA Bulan: Hi Eralyn Forte, your contract for FS-001 ends on December 5, 2026. Please visit the office to renew before this date. Thank you.');
});
