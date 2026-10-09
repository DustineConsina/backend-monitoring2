<?php

namespace App\Services;

class SmsMessageTemplates
{
    public static function overduePayment(
        string $tenantName,
        string $paymentNumber,
        float|string $balance,
        string $month,
        string $dueDate
    ): string {
        return 'PFDA Bulan: Hi ' . $tenantName . ', your rent of P' . number_format((float) $balance, 2)
            . ' for ' . $month . ' was due on ' . $dueDate
            . '. Please pay as soon as possible. Ignore if already paid. Thank you.';
    }

    public static function contractRenewal(
        string $tenantName,
        string $stallNumber,
        string $endDate
    ): string {
        return 'PFDA Bulan: Hi ' . $tenantName . ', your contract for ' . $stallNumber . ' ends on ' . $endDate
            . '. Please visit the office to renew before this date. Thank you.';
    }
}
