<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\RentalSpace;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;

class ReportController extends Controller
{
    /**
     * Convert data to CSV with readable columns matching report tables
     */
    private function generateCSV($data, $fileName, $reportType = 'default')
    {
        if (empty($data)) {
            return response('No data to export', 400);
        }

        // Ensure all data is properly converted to arrays
        $data = $this->recursiveToArray($data);

        // Transform data based on report type
        $transformed = [];
        foreach ($data as $row) {
            $transformed[] = $this->transformRowForCsv($row, $reportType);
        }

        if (empty($transformed)) {
            return response('No data to export', 400);
        }

        // Get column headers
        $headers = array_keys($transformed[0]);

        // Create CSV content
        $csv = implode(',', array_map('self::escapeCsv', $headers)) . "\n";

        foreach ($transformed as $row) {
            $values = [];
            foreach ($headers as $header) {
                $value = $row[$header] ?? '';
                // Convert arrays/objects to string representation
                if (is_array($value) || is_object($value)) {
                    $value = '';
                }
                $values[] = $value;
            }
            $csv .= implode(',', array_map('self::escapeCsv', $values)) . "\n";
        }

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            'Content-Length' => strlen($csv),
        ]);
    }

    /**
     * Recursively convert objects to arrays
     */
    private function recursiveToArray($data)
    {
        if (is_object($data)) {
            $data = $data->toArray();
        }

        if (!is_array($data)) {
            return $data;
        }

        $result = [];
        foreach ($data as $key => $value) {
            if (is_object($value)) {
                $result[$key] = $this->recursiveToArray($value->toArray());
            } elseif (is_array($value)) {
                $result[$key] = $this->recursiveToArray($value);
            } elseif (is_string($value) && (strpos($value, '{') === 0 || strpos($value, '[') === 0)) {
                // Try to decode JSON strings
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $result[$key] = $this->recursiveToArray($decoded);
                } else {
                    $result[$key] = $value;
                }
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Transform row to match report table display
     */
    private function transformRowForCsv($row, $reportType = 'default')
    {
        if (!is_array($row)) {
            if (is_object($row)) {
                $row = $row->toArray();
            } else {
                return [];
            }
        }

        switch ($reportType) {
            case 'contracts':
                $tenant = $row['tenant'][0] ?? ($row['tenant'] ?? []);
                $rentalSpace = $row['rental_space'][0] ?? ($row['rental_space'] ?? []);
                if (is_array($tenant) && !empty($tenant) && !isset($tenant['business_name'])) {
                    $tenant = reset($tenant);
                }
                if (is_array($rentalSpace) && !empty($rentalSpace) && !isset($rentalSpace['name'])) {
                    $rentalSpace = reset($rentalSpace);
                }
                $spaceType = isset($rentalSpace['space_type']) ? str_replace('_', ' ', ucfirst($rentalSpace['space_type'])) : '';
                return [
                    'Contract #' => $row['contract_number'] ?? '',
                    'Tenant' => $tenant['contact_person'] ?? ($tenant['business_name'] ?? ''),
                    'Rental Space' => $rentalSpace['name'] ?? '',
                    'Space Type' => $spaceType,
                    'Monthly Rental' => 'PHP ' . number_format($row['monthly_rental'] ?? 0, 2),
                    'Start Date' => $row['start_date'] ?? '',
                    'End Date' => $row['end_date'] ?? '',
                    'Status' => $row['status'] ?? '',
                    'Terms & Conditions' => $row['terms_conditions'] ?? '',
                ];

            case 'payments':
                $tenant = is_array($row['tenant']) ? $row['tenant'] : [];
                $contract = is_array($row['contract']) ? $row['contract'] : [];
                return [
                    'Payment #' => $row['payment_number'] ?? '',
                    'Tenant' => $tenant['contact_person'] ?? ($tenant['business_name'] ?? ''),
                    'Contract #' => $contract['contract_number'] ?? '',
                    'Amount Due' => 'PHP ' . number_format($row['amount_due'] ?? 0, 2),
                    'Amount Paid' => 'PHP ' . number_format($row['amount_paid'] ?? 0, 2),
                    'Due Date' => $row['due_date'] ?? '',
                    'Status' => $row['status'] ?? '',
                ];

            case 'delinquency':
                return [
                    'Tenant' => $row['business_name'] ?? '',
                    'Contact Person' => $row['contact_person'] ?? '',
                    'Email' => $row['email'] ?? '',
                    'Total Overdue' => 'PHP ' . number_format($row['total_overdue'] ?? 0, 2),
                    'Days Overdue' => $row['days_overdue'] ?? 0,
                ];

            case 'revenue':
                return [
                    'Month' => $row['month'] ?? '',
                    'Expected Revenue' => 'PHP ' . number_format($row['expected_revenue'] ?? 0, 2),
                    'Received Revenue' => 'PHP ' . number_format($row['received_revenue'] ?? 0, 2),
                    'Pending Revenue' => 'PHP ' . number_format($row['pending_revenue'] ?? 0, 2),
                    'Collection Rate' => $row['collection_rate'] ?? '0%',
                ];

            case 'tenants':
                $tenant = is_array($row['tenant']) ? $row['tenant'] : [];
                $user = is_array($tenant['user']) ? $tenant['user'] : [];
                return [
                    'Business Name' => $tenant['business_name'] ?? '',
                    'Contact Person' => $tenant['contact_person'] ?? '',
                    'Email' => $user['email'] ?? '',
                    'Phone' => $user['phone'] ?? '',
                    'Business Permit Number' => $tenant['business_permit_number'] ?? '',
                    'Status' => $tenant['status'] ?? '',
                ];

            case 'expiring-contracts':
                $tenant = is_array($row['tenant']) ? $row['tenant'] : [];
                $rentalSpace = is_array($row['rental_space']) ? $row['rental_space'] : [];
                $spaceType = isset($rentalSpace['space_type']) ? str_replace('_', ' ', ucfirst($rentalSpace['space_type'])) : '';
                return [
                    'Contract #' => $row['contract_number'] ?? '',
                    'Tenant' => $tenant['contact_person'] ?? ($tenant['business_name'] ?? ''),
                    'Rental Space' => $rentalSpace['name'] ?? '',
                    'Space Type' => $spaceType,
                    'End Date' => $row['end_date'] ?? '',
                    'Days Until Expiring' => $row['days_until_expiring'] ?? 0,
                ];

            case 'audit-log':
                $user = is_array($row['user']) ? $row['user'] : [];
                return [
                    'Timestamp' => $row['created_at'] ?? '',
                    'User' => $user['name'] ?? '',
                    'Action' => $row['action'] ?? '',
                    'Model Type' => $row['model_type'] ?? '',
                    'Description' => $row['description'] ?? '',
                ];

            default:
                return $row;
        }
    }

    /**
     * Escape CSV values
     */
    private static function escapeCsv($value)
    {
        $value = (string)$value;
        if (strpos($value, ',') !== false || strpos($value, '"') !== false || strpos($value, "\n") !== false) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }

    /**
     * Get dashboard statistics
     */
    public function dashboardStats(Request $request)
    {
        Contract::updateStatuses();
        Payment::syncStatuses();

        // Count spaces without active contracts (true available)
        $availableSpacesCount = RentalSpace::whereDoesntHave('contracts', function ($q) {
            $q->whereIn('status', ['active', 'for_renewal']);
        })->count();
        
        // Count spaces with active contracts (truly occupied)
        $occupiedSpacesCount = RentalSpace::whereHas('contracts', function ($q) {
            $q->whereIn('status', ['active', 'for_renewal']);
        })->count();

        $pendingPaymentsCount = Payment::whereIn('status', ['pending', 'partial'])
            ->where(function ($query) {
                $query->where('balance', '>', 0)
                    ->orWhereNull('balance');
            })->count();

        $overduePaymentsCount = Payment::where(function ($query) {
            $query->where('status', 'overdue')
                ->orWhere(function ($dueQuery) {
                    $dueQuery->whereIn('status', ['pending', 'partial'])
                        ->whereNotNull('due_date')
                        ->where('due_date', '<', Carbon::now())
                        ->where('balance', '>', 0);
                });
        })->count();
        
        $stats = [
            'totalTenants' => Tenant::where('status', 'active')->count(),
            'totalRentalSpaces' => RentalSpace::count(),
            'availableSpaces' => $availableSpacesCount,
            'occupiedSpaces' => $occupiedSpacesCount,
            'totalContracts' => Contract::count(),
            'activeContracts' => Contract::where('status', 'active')->count(),
            'renewalContracts' => Contract::where('status', 'for_renewal')->count(),
            'expiringContracts' => Contract::where('status', 'for_renewal')
                ->where('end_date', '>', Carbon::now())
                ->where('end_date', '<=', Carbon::now()->addMonths(2))
                ->count(),
            'expiredContracts' => Contract::where('status', 'expired')->count(),
            'terminatedContracts' => Contract::where('status', 'terminated')->count(),
            'pendingPayments' => $pendingPaymentsCount,
            'overduePayments' => $overduePaymentsCount,
            'partialPayments' => Payment::where('status', 'partial')->count(),
            'totalRevenue' => Payment::where('status', 'paid')->sum('amount_paid'),
            'monthlyRevenue' => Payment::where('status', 'paid')
                ->whereMonth('payment_date', Carbon::now()->month)
                ->sum('amount_paid'),
            'delinquentTenants' => Tenant::whereHas('contracts', function($q) {
                $q->whereHas('payments', function($pq) {
                    $pq->where('status', 'overdue');
                });
            })->count(),
        ];

        // Recent payments
        $recentPayments = Payment::with(['tenant.user', 'contract.rentalSpace'])
            ->latest('payment_date')
            ->take(5)
            ->get()
            ->map(function($payment) {
                return [
                    'id' => $payment->id,
                    'paymentFor' => $payment->tenant?->business_name ?? 'Unknown Tenant',
                    'paymentDate' => $payment->payment_date ?? $payment->due_date ?? $payment->created_at,
                    'totalAmount' => round((float) ($payment->total_amount ?? $payment->amount_due ?? 0), 2),
                ];
            });

        // Expiring contracts
        $expiringContracts = Contract::with(['tenant.user', 'rentalSpace'])
            ->where('status', 'for_renewal')
            ->where('end_date', '>', Carbon::now())
            ->where('end_date', '<=', Carbon::now()->addMonths(2))
            ->latest()
            ->take(5)
            ->get();

        // Contracts for renewal
        $renewalContractsList = Contract::with(['tenant.user', 'rentalSpace'])
            ->where('status', 'for_renewal')
            ->latest()
            ->take(5)
            ->get();

        return response()->json([
            'success' => true,
            'data' => array_merge($stats, [
                'recentPayments' => $recentPayments,
                'recentContracts' => $expiringContracts,
                'expiringContracts' => $expiringContracts,
                'renewalContractsList' => $renewalContractsList,
            ])
        ]);
    }

    /**
     * Generate contracts report
     */
    public function contractsReport(Request $request)
    {
        Contract::updateStatuses();

        $query = Contract::with(['tenant.user', 'rentalSpace']);

        // Filter by status
        if ($request->has('status')) {
            if ($request->status === 'all') {
                // Do nothing, get all statuses
            } else {
                $query->where('status', $request->status);
            }
        }

        // Filter by date range
        if ($request->has('date_from')) {
            $query->where('start_date', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->where('start_date', '<=', $request->date_to);
        }

        // Filter by space type
        if ($request->has('space_type')) {
            $query->whereHas('rentalSpace', function ($q) use ($request) {
                $q->where('space_type', $request->space_type);
            });
        }

        $contracts = $query->orderBy('created_at', 'desc')->get();

        // Convert to arrays for PDF/CSV with proper nested structure
        $contractsArray = $contracts->map(function ($contract) {
            $contractArray = $contract->toArray();
            // Ensure rental_space is an array
            if ($contract->rentalSpace) {
                $contractArray['rental_space'] = $contract->rentalSpace->toArray();
            }
            // Ensure tenant is an array
            if ($contract->tenant) {
                $contractArray['tenant'] = $contract->tenant->toArray();
            }
            return $contractArray;
        })->toArray();

        $summary = [
            'total contracts' => $contracts->count(),
            'active contracts' => $contracts->where('status', 'active')->count(),
            'expired contracts' => $contracts->where('status', 'expired')->count(),
            'terminated contracts' => $contracts->where('status', 'terminated')->count(),
            'pending contracts' => $contracts->where('status', 'pending')->count(),
            'total monthly revenue' => $contracts->where('status', 'active')->sum('monthly_rental'),
        ];

        if ($request->has('format') && $request->format === 'pdf') {
            $pdf = Pdf::loadView('reports.contracts', [
                'contracts' => $contractsArray,
                'summary' => $summary,
                'filters' => $request->all(),
            ]);
            return $pdf->download('contracts-report-' . date('Y-m-d') . '.pdf');
        }

        if ($request->has('format') && $request->format === 'csv') {
            // Build CSV directly
            $csv = "Contract #,Tenant,Rental Space,Space Type,Monthly Rental,Start Date,End Date,Status,Terms & Conditions\n";
            foreach ($contracts as $contract) {
                $tenantName = $contract->tenant->contact_person ?? $contract->tenant->business_name ?? '';
                $spaceName = $contract->rentalSpace->name ?? '';
                $spaceType = str_replace('_', ' ', ucfirst($contract->rentalSpace->space_type ?? ''));
                $monthlyRental = number_format($contract->monthly_rental ?? 0, 2);
                $csv .= '"' . $contract->contract_number . '","' . $tenantName . '","' . $spaceName . '","' . $spaceType . '","' . $monthlyRental . '","' . $contract->start_date . '","' . $contract->end_date . '","' . $contract->status . '","' . $contract->terms_conditions . "\"\n";
            }
            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="contracts-report-' . date('Y-m-d') . '.csv"',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'contracts' => $contracts,
                'summary' => $summary,
            ]
        ]);
    }

    /**
     * Generate payments report
     */
    public function paymentsReport(Request $request)
    {
        $query = Payment::with(['tenant.user', 'contract.rentalSpace']);

        // Filter by status
        if ($request->has('status')) {
            if ($request->status === 'all') {
                // Do nothing
            } else {
                $query->where('status', $request->status);
            }
        }

        // Filter by date range
        if ($request->has('date_from')) {
            $query->where('payment_date', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->where('payment_date', '<=', $request->date_to);
        }

        // Filter by tenant
        if ($request->has('tenant_id')) {
            $query->where('tenant_id', $request->tenant_id);
        }

        $payments = $query->orderBy('payment_date', 'desc')->get();

        $summary = [
            'total payments' => $payments->count(),
            'paid payments' => $payments->where('status', 'paid')->count(),
            'pending payments' => $payments->where('status', 'pending')->count(),
            'overdue payments' => $payments->where('status', 'overdue')->count(),
            'partial payments' => $payments->where('status', 'partial')->count(),
            'total collected' => $payments->where('status', 'paid')->sum('amount_paid'),
            'total pending' => $payments->whereIn('status', ['pending', 'overdue', 'partial'])->sum('balance'),
            'total interest charged' => $payments->sum('interest_amount'),
        ];

        // Convert to arrays for PDF with proper nested structure
        $paymentsArray = $payments->map(function ($payment) {
            $paymentArray = $payment->toArray();
            // Ensure tenant is an array
            if ($payment->tenant) {
                $paymentArray['tenant'] = $payment->tenant->toArray();
            }
            // Ensure contract is an array
            if ($payment->contract) {
                $paymentArray['contract'] = $payment->contract->toArray();
            }
            return $paymentArray;
        })->toArray();

        if ($request->has('format') && $request->format === 'pdf') {
            $pdf = Pdf::loadView('reports.payments', [
                'payments' => $paymentsArray,
                'summary' => $summary,
                'filters' => $request->all(),
            ]);
            return $pdf->download('payments-report-' . date('Y-m-d') . '.pdf');
        }

        if ($request->has('format') && $request->format === 'csv') {
            $csv = "Payment #,Tenant,Contract #,Amount Due,Amount Paid,Due Date,Status\n";
            foreach ($payments as $payment) {
                $tenantName = $payment->tenant->contact_person ?? $payment->tenant->business_name ?? '';
                $contractNum = $payment->contract->contract_number ?? '';
                $amountDue = number_format($payment->amount_due ?? 0, 2);
                $amountPaid = number_format($payment->amount_paid ?? 0, 2);
                $csv .= "\"" . $payment->payment_number . "\",\"" . $tenantName . "\",\"" . $contractNum . "\",\"" . $amountDue . "\",\"" . $amountPaid . "\",\"" . $payment->due_date . "\",\"" . $payment->status . "\"\n";
            }
            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="payments-report-' . date('Y-m-d') . '.csv"',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'payments' => $payments,
                'summary' => $summary,
            ]
        ]);
    }

    /**
     * Generate delinquency report
     */
    public function delinquencyReport(Request $request)
    {
        $overduePayments = Payment::with(['tenant.user', 'contract.rentalSpace'])
            ->where('status', 'overdue')
            ->orderBy('due_date', 'asc')
            ->get();

        $delinquentTenants = $overduePayments->groupBy('tenant_id')->map(function ($payments, $tenantId) {
            $tenant = Tenant::with('user')->find($tenantId);
            return [
                'tenant' => $tenant,
                'overdue_count' => $payments->count(),
                'total_balance' => $payments->sum('balance'),
                'total_interest' => $payments->sum('interest_amount'),
                'oldest_due_date' => $payments->min('due_date'),
                'payments' => $payments,
            ];
        })->sortByDesc('total_balance')->values();

        $summary = [
            'total delinquent tenants' => $delinquentTenants->count(),
            'total overdue payments' => $overduePayments->count(),
            'total outstanding balance' => $overduePayments->sum('balance'),
            'total interest charges' => $overduePayments->sum('interest_amount'),
        ];

        // Convert tenants to arrays for PDF
        $delinquentTenantsArray = $delinquentTenants->map(function ($item) {
            return [
                'tenant' => $item['tenant']->toArray(),
                'overdue_count' => $item['overdue_count'],
                'total_balance' => $item['total_balance'],
                'total_interest' => $item['total_interest'],
                'oldest_due_date' => $item['oldest_due_date'],
            ];
        })->toArray();

        if ($request->has('format') && $request->format === 'pdf') {
            $pdf = Pdf::loadView('reports.delinquency', [
                'delinquent_tenants' => $delinquentTenantsArray,
                'summary' => $summary,
            ]);
            return $pdf->download('delinquency-report-' . date('Y-m-d') . '.pdf');
        }

        if ($request->has('format') && $request->format === 'csv') {
            $csv = "Tenant,Email,Total Overdue,Days Overdue\n";
            foreach ($delinquentTenants as $tenant) {
                $tenantName = $tenant['tenant']->contact_person ?? $tenant['tenant']->business_name ?? '';
                $email = $tenant['tenant']->user ? $tenant['tenant']->user->email : '';
                $totalOverdue = number_format($tenant['total_balance'] ?? 0, 2);
                $csv .= "\"" . $tenantName . "\",\"" . $email . "\",\"" . $totalOverdue . "\",\"" . ($tenant['overdue_count'] ?? 0) . "\"\n";
            }
            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="delinquency-report-' . date('Y-m-d') . '.csv"',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'delinquent_tenants' => $delinquentTenants,
                'summary' => $summary,
            ]
        ]);
    }

    /**
     * Generate revenue report
     */
    public function revenueReport(Request $request)
    {
        $year = $request->get('year', Carbon::now()->year);
        $month = $request->get('month');

        $query = Payment::where('status', 'paid');

        if ($month) {
            $query->whereYear('payment_date', $year)
                  ->whereMonth('payment_date', $month);
        } else {
            $query->whereYear('payment_date', $year);
        }

        $payments = $query->with(['tenant.user', 'contract.rentalSpace'])->get();

        // Monthly breakdown
        $monthlyRevenue = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthRevenue = Payment::where('status', 'paid')
                ->whereYear('payment_date', $year)
                ->whereMonth('payment_date', $m)
                ->sum('amount_paid');
            
            $monthlyRevenue[] = [
                'month' => Carbon::create($year, $m, 1)->format('F'),
                'revenue' => $monthRevenue,
            ];
        }

        // By space type
        $revenueBySpaceType = [
            'food_stall' => Payment::where('status', 'paid')
                ->whereYear('payment_date', $year)
                ->whereHas('contract.rentalSpace', function ($q) {
                    $q->where('space_type', 'food_stall');
                })
                ->sum('amount_paid'),
            'market_hall' => Payment::where('status', 'paid')
                ->whereYear('payment_date', $year)
                ->whereHas('contract.rentalSpace', function ($q) {
                    $q->where('space_type', 'market_hall');
                })
                ->sum('amount_paid'),
            'banera_warehouse' => Payment::where('status', 'paid')
                ->whereYear('payment_date', $year)
                ->whereHas('contract.rentalSpace', function ($q) {
                    $q->where('space_type', 'banera_warehouse');
                })
                ->sum('amount_paid'),
        ];

        $summary = [
            'total_revenue' => $payments->sum('amount_paid'),
            'payment_count' => $payments->count(),
            'average_payment' => $payments->count() > 0 ? $payments->sum('amount_paid') / $payments->count() : 0,
        ];

        // Convert payments to arrays for PDF
        $paymentsArray = $payments->map(function ($payment) {
            $paymentArray = $payment->toArray();
            if ($payment->tenant) {
                $paymentArray['tenant'] = $payment->tenant->toArray();
            }
            if ($payment->contract && $payment->contract->rentalSpace) {
                $paymentArray['contract'] = $payment->contract->toArray();
                $paymentArray['contract']['rental_space'] = $payment->contract->rentalSpace->toArray();
            }
            return $paymentArray;
        })->toArray();

        if ($request->has('format') && $request->format === 'pdf') {
            $pdf = Pdf::loadView('reports.revenue', [
                'payments' => $paymentsArray,
                'monthlyRevenue' => $monthlyRevenue,
                'revenueBySpaceType' => $revenueBySpaceType,
                'summary' => $summary,
                'year' => $year,
                'month' => $month,
            ]);
            return $pdf->download('revenue-report-' . $year . ($month ? '-' . str_pad($month, 2, '0', STR_PAD_LEFT) : '') . '.pdf');
        }

        if ($request->has('format') && $request->format === 'csv') {
            $csv = "Month,Expected Revenue,Received Revenue,Pending Revenue,Collection Rate\n";
            foreach ($monthlyRevenue as $month => $revenue) {
                $expectedRev = $revenue['expected'] ?? 0;
                $receivedRev = $revenue['received'] ?? 0;
                $pendingRev = $revenue['pending'] ?? 0;
                $rate = ($receivedRev / ($expectedRev ?: 1) * 100);
                $csv .= "\"" . $month . "\",\"" . $expectedRev . "\",\"" . $receivedRev . "\",\"" . $pendingRev . "\",\"" . $rate . "%\"\n";
            }
            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="revenue-report-' . date('Y-m-d') . '.csv"',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'payments' => $payments,
                'monthly_revenue' => $monthlyRevenue,
                'revenue_by_space_type' => $revenueBySpaceType,
                'summary' => $summary,
            ]
        ]);
    }

    /**
     * Generate tenants report
     */
    public function tenantsReport(Request $request)
    {
        $query = Tenant::with(['user', 'activeContracts.rentalSpace']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $tenants = $query->get()->map(function ($tenant) {
            return [
                'tenant' => $tenant,
                'active_contracts_count' => $tenant->activeContracts->count(),
                'total_contracts_count' => $tenant->contracts->count(),
                'pending_payments_count' => $tenant->payments()->where('status', 'pending')->count(),
                'overdue_payments_count' => $tenant->overduePayments()->count(),
                'total_balance' => $tenant->payments()->whereIn('status', ['pending', 'overdue', 'partial'])->sum('balance'),
            ];
        });

        $summary = [
            'total_tenants' => $tenants->count(),
            'active_tenants' => $tenants->where('tenant.status', 'active')->count(),
            'inactive_tenants' => $tenants->where('tenant.status', 'inactive')->count(),
            'blacklisted_tenants' => $tenants->where('tenant.status', 'blacklisted')->count(),
            'tenants_with_active_contracts' => $tenants->where('active_contracts_count', '>', 0)->count(),
            'tenants_with_overdue_payments' => $tenants->where('overdue_payments_count', '>', 0)->count(),
        ];

        // Convert tenants to arrays for PDF
        $tenantsArray = array_map(function ($item) {
            return [
                'tenant' => $item['tenant']->toArray(),
                'active_contracts_count' => $item['active_contracts_count'],
                'total_contracts_count' => $item['total_contracts_count'],
                'pending_payments_count' => $item['pending_payments_count'],
                'overdue_payments_count' => $item['overdue_payments_count'],
                'total_balance' => $item['total_balance'],
            ];
        }, $tenants->toArray());

        if ($request->has('format') && $request->format === 'pdf') {
            $pdf = Pdf::loadView('reports.tenants', [
                'tenants' => $tenantsArray,
                'summary' => $summary,
            ]);
            return $pdf->download('tenants-report-' . date('Y-m-d') . '.pdf');
        }

        if ($request->has('format') && $request->format === 'csv') {
            $csv = "Business Name,Contact Person,Email,Phone,Business Permit Number,Status\n";
            foreach ($tenants as $tenant) {
                $email = $tenant['tenant']->user ? $tenant['tenant']->user->email : '';
                $phone = $tenant['tenant']->user ? $tenant['tenant']->user->phone : '';
                $csv .= "\"" . ($tenant['tenant']->business_name ?? '') . "\",\"" . ($tenant['tenant']->contact_person ?? '') . "\",\"" . $email . "\",\"" . $phone . "\",\"" . ($tenant['tenant']->business_permit_number ?? '') . "\",\"" . ($tenant['tenant']->status ?? '') . "\"\n";
            }
            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="tenants-report-' . date('Y-m-d') . '.csv"',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'tenants' => $tenants,
                'summary' => $summary,
            ]
        ]);
    }

    /**
     * Generate expiring contracts report
     */
    public function expiringContractsReport(Request $request)
    {
        Contract::updateStatuses();

        $days = $request->get('days', 30); // Default to 30 days

        $expiringContracts = Contract::with(['tenant.user', 'rentalSpace'])
            ->where('status', 'active')
            ->where('end_date', '>=', Carbon::now())
            ->where('end_date', '<=', Carbon::now()->addDays($days))
            ->orderBy('end_date', 'asc')
            ->get();

        $summary = [
            'total_expiring' => $expiringContracts->count(),
            'next_7_days' => $expiringContracts->where('end_date', '<=', Carbon::now()->addDays(7))->count(),
            'next_14_days' => $expiringContracts->where('end_date', '<=', Carbon::now()->addDays(14))->count(),
            'next_30_days' => $expiringContracts->where('end_date', '<=', Carbon::now()->addDays(30))->count(),
        ];

        // Convert to arrays for PDF with proper nested structure
        $contractsArray = $expiringContracts->map(function ($contract) {
            $contractArray = $contract->toArray();
            // Ensure rental_space is an array
            if ($contract->rentalSpace) {
                $contractArray['rental_space'] = $contract->rentalSpace->toArray();
            }
            // Ensure tenant is an array
            if ($contract->tenant) {
                $contractArray['tenant'] = $contract->tenant->toArray();
            }
            return $contractArray;
        })->toArray();

        if ($request->has('format') && $request->format === 'pdf') {
            $pdf = Pdf::loadView('reports.expiring-contracts', [
                'expiring_contracts' => $contractsArray,
                'summary' => $summary,
                'days' => $days,
            ]);
            return $pdf->download('expiring-contracts-report-' . date('Y-m-d') . '.pdf');
        }

        if ($request->has('format') && $request->format === 'csv') {
            $csv = "Contract #,Tenant,Rental Space,Space Type,End Date,Days Until Expiring\n";
            foreach ($expiringContracts as $contract) {
                $tenantName = $contract->tenant ? ($contract->tenant->contact_person ?? $contract->tenant->business_name ?? '') : '';
                $spaceName = $contract->rentalSpace ? ($contract->rentalSpace->name ?? '') : '';
                $spaceType = $contract->rentalSpace ? str_replace('_', ' ', ucfirst($contract->rentalSpace->space_type ?? '')) : '';
                $csv .= "\"" . $contract->contract_number . "\",\"" . $tenantName . "\",\"" . $spaceName . "\",\"" . $spaceType . "\",\"" . $contract->end_date . "\",\"" . ($contract->days_until_expiring ?? 0) . "\"\n";
            }
            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="expiring-contracts-report-' . date('Y-m-d') . '.csv"',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'expiring_contracts' => $expiringContracts,
                'summary' => $summary,
            ]
        ]);
    }

    /**
     * Generate audit log report
     */
    public function auditLogReport(Request $request)
    {
        $query = AuditLog::with('user')
            ->whereRaw('LOWER(action) <> ?', ['view']);

        // Filter by user
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by action
        if ($request->has('action')) {
            $query->where('action', $request->action);
        }

        // Filter by model type
        if ($request->has('model_type')) {
            $query->where('model_type', $request->model_type);
        }

        // Filter by date range
        if ($request->has('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->where('created_at', '<=', $request->date_to);
        }

        $auditLogs = $query->orderBy('created_at', 'desc')->get();

        $summary = [
            'total_logs' => $auditLogs->count(),
            'create_count' => $auditLogs->where('action', 'create')->count(),
            'update_count' => $auditLogs->where('action', 'update')->count(),
            'delete_count' => $auditLogs->where('action', 'delete')->count(),
        ];

        // For PDF, limit to last 500 entries to avoid memory issues
        $auditLogsForPdf = $auditLogs->take(500);

        // Convert audit logs to arrays for PDF
        $auditLogsArray = $auditLogsForPdf->map(function ($log) {
            $logArray = $log->toArray();
            // Ensure user key always exists
            if ($log->user) {
                $logArray['user'] = $log->user->toArray();
            } else {
                $logArray['user'] = ['name' => 'System'];
            }
            return $logArray;
        })->toArray();

        if ($request->has('format') && $request->format === 'pdf') {
            $pdf = Pdf::loadView('reports.audit-log', [
                'audit_logs' => $auditLogsArray,
                'summary' => $summary,
                'filters' => $request->all(),
            ]);
            return $pdf->download('audit-log-report-' . date('Y-m-d') . '.pdf');
        }

        if ($request->has('format') && $request->format === 'csv') {
            $csv = "Timestamp,User,Action,Model Type,Description\n";
            foreach ($auditLogs as $log) {
                $userName = $log->user ? $log->user->name : 'System';
                $csv .= "\"" . $log->created_at . "\",\"" . $userName . "\",\"" . $log->action . "\",\"" . $log->model_type . "\",\"" . $log->description . "\"\n";
            }
            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="audit-log-report-' . date('Y-m-d') . '.csv"',
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'audit_logs' => $auditLogs,
                'summary' => $summary,
            ]
        ]);
    }
}
