<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Demand Letter - <?php echo e($demandNumber); ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            padding: 0;
            font-size: 12px;
            line-height: 1.6;
            color: #333;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #d32f2f;
            padding-bottom: 15px;
        }

        .company-name {
            font-size: 16px;
            font-weight: bold;
            margin: 0 0 5px 0;
            color: #d32f2f;
        }

        .subtitle {
            font-size: 11px;
            margin: 0;
            color: #666;
        }

        .demand-title {
            font-size: 14px;
            font-weight: bold;
            text-align: center;
            margin: 20px 0 10px 0;
            color: #d32f2f;
            text-decoration: underline;
        }

        .letter-number {
            text-align: right;
            font-size: 11px;
            margin-bottom: 20px;
        }

        .date-info {
            margin-bottom: 20px;
            font-size: 12px;
        }

        .recipient {
            margin-bottom: 15px;
            line-height: 1.8;
        }

        p {
            margin: 12px 0;
            text-align: justify;
            line-height: 1.6;
        }

        .important {
            background-color: #fff3cd;
            border-left: 4px solid #d32f2f;
            padding: 10px 15px;
            margin: 15px 0;
            font-weight: bold;
            color: #d32f2f;
        }

        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }

        .details-table td {
            padding: 8px;
            border-bottom: 1px solid #ddd;
        }

        .details-table .label {
            font-weight: bold;
            width: 40%;
            background-color: #f5f5f5;
        }

        .details-table .value {
            text-align: right;
            background-color: #fafafa;
        }

        .total-row {
            font-weight: bold;
            background-color: #d32f2f !important;
            color: white !important;
        }

        .total-row .label {
            background-color: #d32f2f !important;
        }

        .total-row .value {
            background-color: #d32f2f !important;
        }

        .action-required {
            background-color: #ffebee;
            border: 2px solid #d32f2f;
            padding: 12px;
            margin: 15px 0;
            text-align: center;
            font-weight: bold;
            color: #d32f2f;
        }

        .settlement-deadline {
            color: #d32f2f;
            font-weight: bold;
            font-size: 13px;
        }

        .signature-area {
            margin-top: 40px;
        }

        .signature-section {
            margin-top: 30px;
        }

        .sig-line {
            border-top: 1px solid black;
            width: 200px;
            height: 30px;
            margin: 20px auto 5px auto;
        }

        .sig-label {
            font-weight: bold;
            font-size: 11px;
            text-align: center;
        }

        .footer {
            text-align: center;
            font-size: 10px;
            margin-top: 30px;
            border-top: 1px solid #ccc;
            padding-top: 10px;
            color: #666;
        }

        .warning-stamp {
            text-align: center;
            font-size: 28px;
            font-weight: bold;
            color: #d32f2f;
            opacity: 0.1;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            pointer-events: none;
            z-index: -1;
        }
    </style>
</head>
<body>

<div class="warning-stamp">OVERDUE</div>

<div class="header">
    <div class="company-name">CONTRACT MANAGEMENT SYSTEM</div>
    <div class="subtitle">Official Demand Letter</div>
</div>

<div class="letter-number">
    <strong>Demand Letter No.:</strong> <?php echo e($demandNumber); ?>

</div>

<div class="demand-title">FORMAL DEMAND FOR PAYMENT</div>

<div class="date-info">
    <strong>Issued Date:</strong> <?php echo e($issuedDate); ?><br>
    <strong>Settlement Deadline:</strong> <span class="settlement-deadline"><?php echo e($settlementDeadline); ?></span>
</div>

<div class="recipient">
    <strong>TO:</strong><br>
    <?php echo e($tenantName); ?><br>
    <?php echo e($tenantCompany); ?><br>
    <?php echo e($tenantAddress); ?><br>
    Phone: <?php echo e($tenantPhone); ?>

</div>

<p>RE: FORMAL DEMAND FOR PAYMENT OF OUTSTANDING RENTAL OBLIGATION</p>

<p>
    Dear <?php echo e($tenantName); ?>,
</p>

<p>
    This letter serves as a <strong>FORMAL DEMAND FOR PAYMENT</strong> regarding your outstanding rental obligation for the leased property located at <?php echo e($spaceName); ?> (<?php echo e($spaceCode); ?>).
</p>

<div class="action-required">
    ⚠️ IMMEDIATE ACTION REQUIRED - PAYMENT OVERDUE ⚠️
</div>

<p>
    <strong>Summary of Outstanding Payment:</strong>
</p>

<table class="details-table">
    <tr>
        <td class="label">Rental Period</td>
        <td class="value"><?php echo e($billingPeriod); ?></td>
    </tr>
    <tr>
        <td class="label">Rental Amount (Base)</td>
        <td class="value">₱<?php echo e($rentalAmount); ?></td>
    </tr>
    <tr>
        <td class="label">Interest Charge (3%)</td>
        <td class="value">₱<?php echo e($interestAmount); ?></td>
    </tr>
    <tr>
        <td class="label">Original Due Date</td>
        <td class="value"><?php echo e($originalDueDate); ?></td>
    </tr>
    <tr>
        <td class="label">Days Overdue</td>
        <td class="value"><?php echo e($daysOverdue); ?> days</td>
    </tr>
    <tr class="total-row">
        <td class="label">TOTAL AMOUNT DEMANDED</td>
        <td class="value">₱<?php echo e($totalAmountDemanded); ?></td>
    </tr>
</table>

<div class="important">
    ⚠️ NOTICE: As of <?php echo e($issuedDate); ?>, the above outstanding balance remains unpaid and is now considered OVERDUE.
</div>

<p>
    <strong>Terms of This Demand:</strong>
</p>

<p>
    You are hereby formally demanded to pay the total amount of <strong>₱<?php echo e($totalAmountDemanded); ?></strong> on or before <span class="settlement-deadline"><?php echo e($settlementDeadline); ?></span>.
</p>

<p>
    This letter constitutes formal notice that:
</p>

<ol>
    <li>Payment must be received in full on or before the settlement deadline specified above.</li>
    <li>Failure to settle this obligation within the specified timeframe may result in further legal action and additional penalties.</li>
    <li>All outstanding rent and interest charges must be paid in full to resolve this matter.</li>
    <li>A copy of this demand letter shall be filed in our official records.</li>
</ol>

<p>
    <strong>Payment Instructions:</strong>
</p>

<p>
    Payments should be made to the property owner or authorized representative. Please retain proof of payment for your records.
</p>

<div class="signature-area">
    <p>
        <strong>This Demand Letter is issued in accordance with the Lease Agreement dated <?php echo e($contractDate); ?>.</strong>
    </p>

    <div class="signature-section">
        <div style="text-align: center; margin-top: 30px;">
            <div class="sig-line"></div>
            <div class="sig-label">Authorized Representative</div>
            <div style="font-size: 11px; margin-top: 5px;">Date: <?php echo e($currentDate); ?></div>
        </div>
    </div>
</div>

<div class="footer">
    <p>
        This is an official demand letter. Please keep this document for your records.<br>
        For disputes or payment arrangements, contact the property management office immediately.
    </p>
    <p style="margin-top: 10px; font-style: italic;">
        Generated on: <?php echo e($generatedDate); ?>

    </p>
</div>

</body>
</html>
<?php /**PATH C:\xampp\htdocs\contract_monitoring_backend\resources\views/demand-letters/letter.blade.php ENDPATH**/ ?>