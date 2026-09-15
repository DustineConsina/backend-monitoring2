<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Contracts Report</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        h1 {
            color: #2c3e50;
            border-bottom: 3px solid #3498db;
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
            border-left: 4px solid #3498db;
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
            background: #3498db;
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
        .status {
            padding: 5px 10px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
        }
        .status.active {
            background: #d4edda;
            color: #155724;
        }
        .status.expired {
            background: #f8d7da;
            color: #721c24;
        }
        .status.terminated {
            background: #e2e3e5;
            color: #383d41;
        }
        .status.pending {
            background: #fff3cd;
            color: #856404;
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
    <h1>Contracts Report</h1>
    
    <?php if(isset($summary)): ?>
    <div class="summary">
        <div class="summary-item">
            <label>Total Contracts</label>
            <div class="value"><?php echo e($summary['total_contracts'] ?? 0); ?></div>
        </div>
        <div class="summary-item">
            <label>Active Contracts</label>
            <div class="value"><?php echo e($summary['active_contracts'] ?? 0); ?></div>
        </div>
        <div class="summary-item">
            <label>Expired Contracts</label>
            <div class="value"><?php echo e($summary['expired_contracts'] ?? 0); ?></div>
        </div>
        <div class="summary-item">
            <label>Terminated Contracts</label>
            <div class="value"><?php echo e($summary['terminated_contracts'] ?? 0); ?></div>
        </div>
        <div class="summary-item">
            <label>Pending Contracts</label>
            <div class="value"><?php echo e($summary['pending_contracts'] ?? 0); ?></div>
        </div>
        <div class="summary-item">
            <label>Total Monthly Revenue</label>
            <div class="value">PHP <?php echo e(number_format($summary['total_monthly_revenue'] ?? 0, 2)); ?></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if($contracts && count($contracts) > 0): ?>
    <table>
        <thead>
            <tr>
                <th>Contract #</th>
                <th>Tenant</th>
                <th>Rental Space</th>
                <th>Monthly Rental</th>
                <th>Start Date</th>
                <th>End Date</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php $__currentLoopData = $contracts; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $contract): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <tr>
                <td><?php echo e($contract['contract_number'] ?? 'N/A'); ?></td>
                <td><?php echo e($contract['tenant']['contact_person'] ?? $contract['tenant']['business_name'] ?? 'N/A'); ?></td>
                <td><?php echo e(($contract['rental_space']['name'] ?? 'N/A') . ' (' . str_replace('_', ' ', ucfirst($contract['rental_space']['space_type'] ?? 'N/A')) . ')'); ?></td>
                <td>PHP <?php echo e(number_format($contract['monthly_rental'] ?? 0, 2)); ?></td>
                <td><?php echo e(isset($contract['start_date']) ? date('M d, Y', strtotime($contract['start_date'])) : 'N/A'); ?></td>
                <td><?php echo e(isset($contract['end_date']) ? date('M d, Y', strtotime($contract['end_date'])) : 'N/A'); ?></td>
                <td><span class="status <?php echo e(strtolower($contract['status'] ?? 'pending')); ?>"><?php echo e(ucfirst($contract['status'] ?? 'Pending')); ?></span></td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </tbody>
    </table>
    <?php else: ?>
    <p style="color: #999; text-align: center; margin-top: 20px;">No contracts found</p>
    <?php endif; ?>

    <div class="footer">
        <p>Generated on <?php echo e(date('F d, Y \a\t H:i A')); ?></p>
    </div>
</body>
</html>
<?php /**PATH C:\xampp\htdocs\contract_monitoring_backend\resources\views/reports/contracts.blade.php ENDPATH**/ ?>