<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\CashierController;
use App\Http\Controllers\RentalSpaceController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ReportController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// CORS preflight
Route::options('{any}', function() {
    return response()->json(['status' => 'ok']);
})->where('any', '.*');

// Health check
Route::get('/', fn() => response()->json(['message' => 'PFDA Contract Monitoring API is running', 'status' => 'ok']));
Route::get('/health', function() {
    try {
        \Illuminate\Support\Facades\DB::connection()->getPdo();
        return response()->json([
            'message' => 'PFDA Contract Monitoring API is running',
            'status' => 'ok',
            'database' => 'connected'
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'message' => 'Database connection failed',
            'status' => 'error',
            'error' => $e->getMessage()
        ], 500);
    }
});

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/qr-scan', [TenantController::class, 'scanQRCode']);
Route::get('/storage/{path}', [TenantController::class, 'serveFile'])->where('path', '.*');
Route::get('/contracts/{id}/view', [ContractController::class, 'viewQRContract']);
Route::get('/contracts/{id}/lease', [ContractController::class, 'downloadLease']);
// Public QR scan info requires the tenant's generated 8-digit access code.
Route::post('/public/tenant/{id}', [TenantController::class, 'publicTenantInfo']);

// DEBUG: Direct database check (remove after debugging)
Route::get('/debug/contract/{id}', function($id) {
    $contract = \App\Models\Contract::find($id);
    if (!$contract) {
        return response()->json(['error' => 'Contract not found'], 404);
    }
    return response()->json([
        'id' => $contract->id,
        'contract_number' => $contract->contract_number,
        'monthly_rental_in_db' => $contract->monthly_rental,
        'rental_space_id' => $contract->rental_space_id,
        'updated_at' => $contract->updated_at,
    ]);
});

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/debug', [DashboardController::class, 'debug']);

    // Tenants
    Route::apiResource('tenants', TenantController::class);
    Route::get('/tenants/{id}/qr-code', [TenantController::class, 'getQRCodeWithDetails']);
    Route::post('/tenants/{id}/generate-qr', [TenantController::class, 'generateQRCode']);
    Route::post('/tenants/{id}/upload-picture', [TenantController::class, 'uploadPicture']);

    // Contracts
    Route::apiResource('contracts', ContractController::class);
    Route::get('/contracts/{id}/qr-code', [ContractController::class, 'getQRCode']);
    Route::post('/contracts/{id}/activate', [ContractController::class, 'activate']);
    Route::post('/contracts/{id}/terminate', [ContractController::class, 'terminate']);
    Route::post('/contracts/{id}/renew', [ContractController::class, 'renew']);

    // Payments
    Route::apiResource('payments', PaymentController::class)->only(['index', 'show', 'store']);
    Route::put('/payments/{id}', [PaymentController::class, 'update']);
    Route::patch('/payments/{id}', [PaymentController::class, 'update']);
    Route::delete('/payments/{id}', [PaymentController::class, 'destroy']);
    Route::post('/payments/{id}/record', [PaymentController::class, 'recordPayment']);
    Route::patch('/payments/{id}/status', [PaymentController::class, 'updateStatus']);
    Route::post('/payments/calculate-overdue', [PaymentController::class, 'calculateOverduePayments']);
    Route::get('/payable-contracts', [PaymentController::class, 'getPayableContracts']);
    Route::get('/tenants/{id}/payment-summary', [PaymentController::class, 'getTenantPaymentSummary']);
    Route::get('/tenants/{id}/payment-history', [PaymentController::class, 'getPaymentHistory']);
    
    // Demand Letters - New Routes
    Route::get('/demand-letters', [PaymentController::class, 'getAllDemandLetters']);
    Route::get('/contracts/{contractId}/demand-letters', [PaymentController::class, 'listDemandLetters']);
    Route::get('/demand-letters/{demandLetterId}/download', [PaymentController::class, 'downloadDemandLetter']);
    Route::get('/contracts/{contractId}/payment-summary', [PaymentController::class, 'getContractPaymentSummary']);

    // Rental Spaces
    Route::apiResource('rental-spaces', RentalSpaceController::class);
    Route::get('/rental-spaces-available', [RentalSpaceController::class, 'getAvailableSpaces']);
    Route::get('/rental-spaces-statistics', [RentalSpaceController::class, 'getStatistics']);

    // Chat
    Route::get('/chat/conversations', [ChatController::class, 'getConversations']);
    Route::get('/chat/messages/{userId}', [ChatController::class, 'getMessages']);
    Route::post('/chat/send', [ChatController::class, 'sendMessage']);
    Route::patch('/chat/{id}/read', [ChatController::class, 'markAsRead']);
    Route::get('/chat/unread-count', [ChatController::class, 'getUnreadCount']);
    Route::get('/chat/available-users', [ChatController::class, 'getAvailableUsers']);
    Route::delete('/chat/{id}', [ChatController::class, 'deleteMessage']);

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread', [NotificationController::class, 'getUnread']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'getUnreadCount']);
    Route::get('/notifications/stream', [NotificationController::class, 'stream']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::put('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::put('/notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    Route::delete('/notifications', [NotificationController::class, 'deleteAll']);

    // Reports
    Route::get('/reports/dashboard-stats', [ReportController::class, 'dashboardStats']);
    Route::middleware('role:admin,staff,cashier')->group(function () {
        Route::get('/reports/payments', [ReportController::class, 'paymentsReport']);
    });
    
    // Reports - Admin/Staff only
    Route::middleware('role:admin,staff')->group(function () {
        Route::get('/reports/contracts', [ReportController::class, 'contractsReport']);
        Route::get('/reports/delinquency', [ReportController::class, 'delinquencyReport']);
        Route::get('/reports/revenue', [ReportController::class, 'revenueReport']);
        Route::get('/reports/tenants', [ReportController::class, 'tenantsReport']);
        Route::get('/reports/expiring-contracts', [ReportController::class, 'expiringContractsReport']);
        Route::get('/reports/audit-log', [ReportController::class, 'auditLogReport']);
    });
    
    // Audit logs - accessible to all authenticated users
    Route::get('/audit-logs', [ReportController::class, 'auditLogReport']);

    // Cashier routes
    Route::middleware('role:cashier,admin,staff')->group(function () {
        Route::get('/cashier/todays-collection', [CashierController::class, 'getTodaysCollection']);
        Route::get('/cashier/collectibles', [CashierController::class, 'getCollectibles']);
        Route::post('/cashier/payments/{id}/record', [CashierController::class, 'recordPayment']);
        Route::get('/cashier/payments/{id}/receipt', [CashierController::class, 'getReceipt']);
    });
});
