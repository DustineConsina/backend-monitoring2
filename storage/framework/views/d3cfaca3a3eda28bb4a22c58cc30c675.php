<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Delinquency Report</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        h1 {
            color: #2c3e50;
            border-bottom: 3px solid #e74c3c;
            padding-bottom: 10px;
        }
        .summary {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin: 20px 0;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
        }
        .summary-item {
            background: white;
            padding: 10px;
            border-left: 4px solid #e74c3c;
        }
        .summary-item label {
            font-weight: bold;
            color: #555;
            display: block;
            font-size: 12px;
        }
        .summary-item .value {
            font-size: 18px;
            font-weight: bold;
            color: #2c3e50;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        table thead {
            background: #e74c3c;
            color: white;
        }
        table th {
            padding: 12px;
            text-align: left;
            font-weight: bold;
        }
        table td {
            padding: 10px;
            border-bottom: 1px solid #ddd;
        }
        table tbody tr:nth-child(even) {
            background: #f8f9fa;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 12px;
            color: #999;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
    </style>
</head>
<body>
    <h1>Delinquency Report</h1>
    
    <?php if(isset($summary)): ?>
    <div class="summary">
        <div class="summary-item">
            <label>Total Delinquent Tenants</label>
            <div class="value"><?php echo e($summary['total_delinquent_tenants'] ?? 0); ?></div>
        </div>
        <div class="summary-item">
            <label>Total Overdue Amount</label>
            <div class="value">PHP <?php echo e(number_format($summary['total_overdue_amount'] ?? 0, 2)); ?></div>
        </div>
        <div class="summary-item">
            <label>Tenants 30+ Days Overdue</label>
            <div class="value"><?php echo e($summary['days_30_plus'] ?? 0); ?></div>
        </div>
        <div class="summary-item">
            <label>Tenants 90+ Days Overdue</label>
            <div class="value"><?php echo e($summary['days_90_plus'] ?? 0); ?></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if($delinquent_tenants && count($delinquent_tenants) > 0): ?>
    <table>
        <thead>
            <tr>
                <th>Tenant</th>
                <th>Contact Person</th>
                <th>Email</th>
                <th>Total Overdue</th>
                <th>Days Overdue</th>
            </tr>
        </thead>
        <tbody>
            <?php $__currentLoopData = $delinquent_tenants; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $tenant): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <tr>
                <td><?php echo e($tenant['contact_person'] ?? $tenant['business_name'] ?? 'N/A'); ?></td>
                <td><?php echo e($tenant['contact_person'] ?? 'N/A'); ?></td>
                <td><?php echo e($tenant['email'] ?? 'N/A'); ?></td>
                <td>PHP <?php echo e(number_format($tenant['total_overdue'] ?? 0, 2)); ?></td>
                <td><?php echo e($tenant['days_overdue'] ?? 0); ?> days</td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </tbody>
    </table>
    <?php else: ?>
    <p style="color: #999; text-align: center; margin-top: 20px;">No delinquent tenants found</p>
    <?php endif; ?>

    <div class="footer">
        <p>Generated on <?php echo e(date('F d, Y \a\t H:i A')); ?></p>
    </div>
</body>
</html>
<?php /**PATH C:\xampp\htdocs\contract_monitoring_backend\resources\views/reports/delinquency.blade.php ENDPATH**/ ?>