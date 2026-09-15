<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\RentalSpace;
use App\Models\Notification;
use Illuminate\Http\Request;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics
     */
    public function index(Request $request)
    {
        Contract::updateStatuses();

        $user = $request->user();

        if ($user->isTenant()) {
            return $this->tenantDashboard($user);
        }

        return $this->adminDashboard();
    }

    /**
     * Admin/Staff dashboard
     */
    private function adminDashboard()
    {
        // Count spaces without active or for_renewal contracts (true available)
        $availableSpacesCount = RentalSpace::whereDoesntHave('contracts', function ($q) {
            $q->whereIn('status', ['active', 'for_renewal']);
        })->count();
        
        // Count spaces with active or for_renewal contracts (truly occupied)
        $occupiedSpacesCount = RentalSpace::whereHas('contracts', function ($q) {
            $q->whereIn('status', ['active', 'for_renewal']);
        })->count();
        
        $stats = [
            'total_tenants' => Tenant::where('status', 'active')->count(),
            'total_rental_spaces' => RentalSpace::count(),
            'available_spaces' => $availableSpacesCount,
            'occupied_spaces' => $occupiedSpacesCount,
            'active_contracts' => Contract::where('status', 'active')->count(),
            'renewal_contracts' => Contract::where('status', 'for_renewal')->count(),
            'expiring_contracts' => Contract::whereIn('status', ['active', 'for_renewal'])
                ->where('end_date', '<=', Carbon::now()->addDays(30))
                ->count(),
            'expired_contracts' => Contract::where('status', 'expired')->count(),
            'pending_payments' => Payment::where('status', 'pending')->count(),
            'overdue_payments' => Payment::where('status', 'overdue')->count(),
            'total_revenue_month' => Payment::where('status', 'paid')
                ->whereMonth('payment_date', Carbon::now()->month)
                ->sum('amount_paid'),
            'total_revenue_year' => Payment::where('status', 'paid')
                ->whereYear('payment_date', Carbon::now()->year)
                ->sum('amount_paid'),
            'delinquent_tenants' => Tenant::whereHas('overduePayments')->count(),
        ];

        // Recent contracts
        $recentContracts = Contract::with(['tenant.user', 'rentalSpace'])
            ->latest()
            ->take(5)
            ->get();

        // Upcoming expirations
        $upcomingExpirations = Contract::with(['tenant.user', 'rentalSpace'])
            ->whereIn('status', ['active', 'for_renewal'])
            ->where('end_date', '<=', Carbon::now()->addDays(30))
            ->orderBy('end_date')
            ->get();

        // Recent payments
        $recentPayments = Payment::with(['tenant.user', 'contract'])
            ->latest()
            ->take(5)
            ->get();

        // Overdue payments
        $overduePayments = Payment::with(['tenant.user', 'contract'])
            ->where('status', 'overdue')
            ->orderBy('due_date')
            ->get();

        // Monthly revenue chart data
        $monthlyRevenue = [];
        for ($i = 11; $i >= 0; $i--) {
            $date = Carbon::now()->subMonths($i);
            $monthlyRevenue[] = [
                'month' => $date->format('M Y'),
                'revenue' => Payment::where('status', 'paid')
                    ->whereYear('payment_date', $date->year)
                    ->whereMonth('payment_date', $date->month)
                    ->sum('amount_paid'),
            ];
        }

        // Space utilization (based on actual active/for_renewal contracts, not status field)
        $spaceUtilization = [
            'food_stall' => [
                'total' => RentalSpace::where('space_type', 'food_stall')->count(),
                'occupied' => RentalSpace::where('space_type', 'food_stall')->whereHas('contracts', function ($q) {
                    $q->whereIn('status', ['active', 'for_renewal']);
                })->count(),
            ],
            'market_hall' => [
                'total' => RentalSpace::where('space_type', 'market_hall')->count(),
                'occupied' => RentalSpace::where('space_type', 'market_hall')->whereHas('contracts', function ($q) {
                    $q->whereIn('status', ['active', 'for_renewal']);
                })->count(),
            ],
            'banera_warehouse' => [
                'total' => RentalSpace::where('space_type', 'banera_warehouse')->count(),
                'occupied' => RentalSpace::where('space_type', 'banera_warehouse')->whereHas('contracts', function ($q) {
                    $q->whereIn('status', ['active', 'for_renewal']);
                })->count(),
            ],
        ];

        return response()->json([
            'success' => true,
            'stats' => $stats,
            'recent_contracts' => $recentContracts,
            'upcoming_expirations' => $upcomingExpirations,
            'recent_payments' => $recentPayments,
            'overdue_payments' => $overduePayments,
            'monthly_revenue' => $monthlyRevenue,
            'space_utilization' => $spaceUtilization,
        ]);
    }

    /**
     * Tenant dashboard
     */
    private function tenantDashboard($user)
    {
        $tenant = $user->tenant;

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant profile not found'
            ], 404);
        }

        $stats = [
            'active_contracts' => $tenant->activeContracts()->count(),
            'pending_payments' => $tenant->payments()->where('status', 'pending')->count(),
            'overdue_payments' => $tenant->overduePayments()->count(),
            'total_balance' => $tenant->payments()->whereIn('status', ['pending', 'overdue', 'partial'])->sum('balance'),
        ];

        // Active contracts
        $contracts = $tenant->contracts()
            ->with(['rentalSpace', 'payments'])
            ->where('status', 'active')
            ->get();

        // Recent payments
        $recentPayments = $tenant->payments()
            ->with('contract.rentalSpace')
            ->latest()
            ->take(5)
            ->get();

        // Upcoming payments
        $upcomingPayments = $tenant->payments()
            ->with('contract.rentalSpace')
            ->whereIn('status', ['pending', 'overdue'])
            ->orderBy('due_date')
            ->get();

        // Recent notifications
        $notifications = $user->notifications()
            ->unread()
            ->latest()
            ->take(5)
            ->get();

        return response()->json([
            'success' => true,
            'stats' => $stats,
            'contracts' => $contracts,
            'recent_payments' => $recentPayments,
            'upcoming_payments' => $upcomingPayments,
            'notifications' => $notifications,
        ]);
    }

    /**
     * Debug: Get all users and contracts (admin only)
     */
    public function debug()
    {
        $user = auth()->user();
        
        // Only allow admin
        if ($user->role !== 'admin') {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $users = \App\Models\User::all(['id', 'name', 'email', 'role']);
        $allContracts = Contract::with(['tenant', 'tenant.user', 'rentalSpace'])->get();
        
        return response()->json([
            'success' => true,
            'data' => [
                'users' => $users->toArray(),
                'users_count' =>  $users->count(),
                'contracts_count' => $allContracts->count(),
                'contracts_with_tenant' => $allContracts->filter(fn($c) => $c->tenant !== null)->count(),
                'contracts_with_space' => $allContracts->filter(fn($c) => $c->rentalSpace !== null)->count(),
                'sample_contracts' => $allContracts->take(3)->toArray(),
            ]
        ]);
    }
}
