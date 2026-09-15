<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payments Report</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #333;
            margin: 0;
            padding: 20px;
        }
        h1 {
            color: #2c3e50;
            border-bottom: 3px solid #27ae60;
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
            border-left: 4px solid #27ae60;
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
            background: #27ae60;
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
        .status.paid {
            background: #d4edda;
            color: #155724;
        }
        .status.pending {
            background: #fff3cd;
            color: #856404;
        }
        .status.overdue {
            background: #f8d7da;
            color: #721c24;
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
    <h1>Payments Report</h1>
    
    <?php if(isset($summary)): ?>
    <div class="summary">
        <div class="summary-item">
            <label>Total Payments</label>
            <div class="value"><?php echo e($summary['total_payments'] ?? 0); ?></div>
        </div>
        <div class="summary-item">
            <label>Paid Payments</label>
            <div class="value"><?php echo e($summary['paid_payments'] ?? 0); ?></div>
        </div>
        <div class="summary-item">
            <label>Pending Payments</label>
            <div class="value"><?php echo e($summary['pending_payments'] ?? 0); ?></div>
        </div>
        <div class="summary-item">
            <label>Overdue Payments</label>
            <div class="value"><?php echo e($summary['overdue_payments'] ?? 0); ?></div>
        </div>
        <div class="summary-item">
            <label>Total Amount Due</label>
            <div class="value">PHP <?php echo e(number_format($summary['total_amount_due'] ?? 0, 2)); ?></div>
        </div>
        <div class="summary-item">
            <label>Total Amount Paid</label>
            <div class="value">PHP <?php echo e(number_format($summary['total_amount_paid'] ?? 0, 2)); ?></div>
        </div>
    </div>
    <?php endif; ?>

    <?php if($payments && count($payments) > 0): ?>
    <table>
        <thead>
            <tr>
                <th>Payment #</th>
                <th>Tenant</th>
                <th>Contract #</th>
                <th>Amount Due</th>
                <th>Amount Paid</th>
                <th>Due Date</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php $__currentLoopData = $payments; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $payment): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <tr>
                <td><?php echo e($payment['payment_number'] ?? 'N/A'); ?></td>
                <td><?php echo e($payment['tenant']['contact_person'] ?? $payment['tenant']['business_name'] ?? 'N/A'); ?></td>
                <td><?php echo e($payment['contract']['contract_number'] ?? 'N/A'); ?></td>
                <td>PHP <?php echo e(number_format($payment['amount_due'] ?? 0, 2)); ?></td>
                <td>PHP <?php echo e(number_format($payment['amount_paid'] ?? 0, 2)); ?></td>
                <td><?php echo e(isset($payment['due_date']) ? date('M d, Y', strtotime($payment['due_date'])) : 'N/A'); ?></td>
                <td><span class="status <?php echo e(strtolower($payment['status'] ?? 'pending')); ?>"><?php echo e(ucfirst($payment['status'] ?? 'Pending')); ?></span></td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </tbody>
    </table>
    <?php else: ?>
    <p style="color: #999; text-align: center; margin-top: 20px;">No payments found</p>
    <?php endif; ?>

    <div class="footer">
        <p>Generated on <?php echo e(date('F d, Y \a\t H:i A')); ?></p>
    </div>
</body>
</html>
<?php /**PATH C:\xampp\htdocs\contract_monitoring_backend\resources\views/reports/payments.blade.php ENDPATH**/ ?>