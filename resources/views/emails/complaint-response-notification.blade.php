<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New response to your complaint</title>
</head>
<body style="margin:0;background:#f1f5f9;padding:24px;font-family:Arial,sans-serif;color:#1e293b;">
    <main style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:32px;">
        <p>Dear {{ $tenantName }},</p>
        <p>We would like to inform you that the administration has responded to your complaint.</p>
        <p>Please open your tenant portal to view the response and check the status of your complaint.</p>
        <p style="margin:28px 0;">
            <a href="{{ $portalUrl }}" style="display:inline-block;background:#0b5963;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:8px;font-weight:bold;">
                View My Complaint
            </a>
        </p>
        <p>To protect your information, scan your tenant QR code and enter your QR password to access your complaint.</p>
        <p>Thank you.</p>
        <p>Regards,<br>Contract Management System Administration</p>
    </main>
</body>
</html>
