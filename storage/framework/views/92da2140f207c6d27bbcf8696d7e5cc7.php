<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Lease Contract - <?php echo e($contractNumber); ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            padding: 0;
            font-size: 12px;
            line-height: 1.4;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid black;
            padding-bottom: 15px;
        }

        .title {
            font-size: 16px;
            font-weight: bold;
            margin: 0;
        }

        .subtitle {
            font-size: 12px;
            margin: 5px 0 0 0;
        }

        .contract-info {
            text-align: center;
            font-size: 11px;
            margin-bottom: 20px;
        }

        p {
            margin: 10px 0;
            text-align: justify;
            line-height: 1.5;
        }

        .section-title {
            font-weight: bold;
            font-size: 12px;
            margin-top: 15px;
            margin-bottom: 8px;
            text-decoration: underline;
        }

        .numbered {
            margin: 8px 0 8px 20px;
            text-align: justify;
            line-height: 1.5;
        }

        .signature-area {
            margin-top: 40px;
        }

        .signatures {
            display: table;
            width: 100%;
            margin-top: 30px;
        }

        .sig-col {
            display: table-cell;
            width: 45%;
            text-align: center;
            vertical-align: bottom;
        }

        .sig-line {
            border-bottom: 1px solid black;
            width: 150px;
            height: 50px;
            margin: 0 auto 10px auto;
        }

        .sig-label {
            font-weight: bold;
            font-size: 11px;
        }

        .footer {
            text-align: center;
            font-size: 10px;
            margin-top: 20px;
            border-top: 1px solid #ccc;
            padding-top: 10px;
        }
    </style>
</head>
<body>

<div class="header">
    <div class="title">LEASE AGREEMENT</div>
    <div class="subtitle">Commercial Rental Property Lease</div>
</div>

<div class="contract-info">
    <strong>Contract No: <span style="font-size: 13px; background-color: #ffffcc;"><?php echo e($contractNumber); ?></span> | Date: <span style="font-size: 13px; background-color: #ffffcc;"><?php echo e($contractDate); ?></span></strong>
</div>

<p>This Lease Agreement is entered into on <strong><?php echo e(\Carbon\Carbon::parse($contractDate)->format('F j, Y')); ?></strong> by and between the <strong>Philippine Fisheries Development Authority (PFDA)</strong>, represented hereinafter as <strong>"LESSOR,"</strong> and <strong><?php echo e($tenantName); ?></strong> doing business as <strong><?php echo e($tenantCompany); ?></strong> with address at <strong><?php echo e($tenantAddress); ?></strong>, represented hereinafter as <strong>"LESSEE."</strong></p>

<div class="section-title">PARTIES TO THE AGREEMENT</div>

<p><strong>LESSOR:</strong> <strong>Philippine Fisheries Development Authority (PFDA)</strong>, Fish Port Complex, Bulan, Sorsogon, Philippines</p>

<p><strong>LESSEE:</strong> <strong><?php echo e($tenantName); ?></strong>, Business: <strong><?php echo e($tenantCompany); ?></strong>, Address: <strong><?php echo e($tenantAddress); ?></strong>, Phone: <strong><?php echo e($tenantPhone); ?></strong></p>

<div class="section-title">DESCRIPTION OF LEASED PREMISES</div>

<p>The LESSOR agrees to lease to the LESSEE a commercial space known as <strong><?php echo e($spaceName); ?></strong> (Code: <strong><?php echo e($spaceCode); ?></strong>), classified as <strong><?php echo e($spaceType); ?></strong>, consisting of approximately <strong><?php echo e($spaceSqm); ?> square meters</strong>, located at Fish Port Complex, Bulan, Sorsogon. The Premises are leased in their current condition.</p>

<div class="section-title">TERM OF LEASE</div>

<p>The lease shall commence on <strong><?php echo e($startDate); ?></strong> and terminate on <strong><?php echo e($endDate); ?></strong>, for a period of <strong><?php echo e(intval($totalDurationMonths)); ?> months</strong>. Upon termination, LESSEE shall vacate the Premises in good condition.</p>

<div class="section-title">FINANCIAL TERMS</div>

<div class="numbered">
<strong>1. Monthly Rent:</strong> LESSEE shall pay a monthly rental of <strong style="font-size: 13px; background-color: #ffffcc;"><?php echo e($monthlyRent); ?></strong>, payable in advance on or before the first day of each month.
</div>

<div class="numbered">
<strong>2. Security Deposit:</strong> LESSEE shall provide a security deposit of <strong style="font-size: 13px; background-color: #ffffcc;"><?php echo e($securityDeposit); ?></strong> as security for performance of obligations. This deposit shall be returned within 30 days after lease termination, less deductions for damages or unpaid rent.
</div>

<div class="numbered">
<strong>3. Utilities:</strong> LESSEE is responsible for all utilities including electricity, water, and other services.
</div>

<div class="section-title">TERMS AND CONDITIONS</div>

<div class="numbered">
<strong>1. Use of Premises:</strong> LESSEE shall use the Premises for commercial purposes only, in compliance with all applicable laws.
</div>

<div class="numbered">
<strong>2. Maintenance:</strong> LESSEE shall maintain the Premises in good condition and make necessary repairs. LESSOR is responsible for structural repairs.
</div>

<div class="numbered">
<strong>3. Compliance with Law:</strong> LESSEE shall comply with all national, provincial, municipal, and local laws and regulations.
</div>

<div class="numbered">
<strong>4. Insurance:</strong> LESSEE shall maintain adequate insurance coverage for the leased space.
</div>

<div class="numbered">
<strong>5. No Subletting:</strong> LESSEE shall not sublet or assign this lease without LESSOR's written consent.
</div>

<div class="numbered">
<strong>6. Termination:</strong> Either party may terminate with 30 days written notice. LESSOR may terminate immediately for material breach.
</div>

<?php if($terms): ?>
<div class="section-title">ADDITIONAL TERMS</div>
<p><?php echo e($terms); ?></p>
<?php endif; ?>

<div class="signature-area">
    <p style="text-align: center; margin: 20px 0;"><strong>IN WITNESS WHEREOF,</strong> the parties have executed this Agreement.</p>

    <div class="signatures">
        <div class="sig-col">
            <div class="sig-line"></div>
            <div class="sig-label">LESSOR / PFDA Representative</div>
            <p style="font-size: 10px; margin-top: 8px;">Signature over Printed Name</p>
        </div>
        <div class="sig-col">
            <div class="sig-line"></div>
            <div class="sig-label">LESSEE / TENANT</div>
            <p style="font-size: 10px; margin-top: 8px;"><?php echo e($tenantName); ?></p>
        </div>
    </div>

    <p style="margin-top: 30px;">Date: ______________________</p>
</div>

<div class="footer">
    Contract No. <?php echo e($contractNumber); ?> | Generated on <?php echo e(date('F d, Y')); ?>

</div>

</body>
</html>
<?php /**PATH C:\xampp\htdocs\contract_monitoring_backend\resources\views/contracts/lease.blade.php ENDPATH**/ ?>