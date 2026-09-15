<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\Tenant;
use App\Models\User;
use App\Models\RentalSpace;
use App\Models\AuditLog;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Carbon\Carbon;

class ContractController extends Controller
{
    /**
     * Generate a unique contract number for the current year.
     */
    private function generateContractNumber(): string
    {
        $year = date('Y');
        $prefix = "CON-{$year}-";

        $latestContract = Contract::withTrashed()
            ->where('contract_number', 'like', $prefix . '%')
            ->orderByRaw("CAST(SUBSTRING(contract_number, LENGTH('{$prefix}') + 1) AS UNSIGNED) DESC")
            ->first();

        $nextNumber = 1;

        if ($latestContract && preg_match('/CON-\d{4}-(\d{6})$/', $latestContract->contract_number, $matches)) {
            $nextNumber = (int) $matches[1] + 1;
        }

        return $prefix . str_pad((string) $nextNumber, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Display a listing of contracts
     */
    public function index(Request $request)
    {
        Contract::updateStatuses();

        $query = Contract::with(['tenant.user', 'rentalSpace', 'payments']);

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('contract_number', 'like', "%{$search}%")
                    ->orWhereHas('tenant', function ($q) use ($search) {
                        $q->where('business_name', 'like', "%{$search}%")
                            ->orWhere('tenant_code', 'like', "%{$search}%");
                    })
                    ->orWhereHas('rentalSpace', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('space_code', 'like', "%{$search}%");
                    });
            });
        }

        // Filter by status (case-insensitive)
        if ($request->has('status')) {
            $query->where('status', strtolower($request->status));
        }

        // Filter by rental space type (case-insensitive)
        if ($request->has('space_type')) {
            $query->whereHas('rentalSpace', function ($q) use ($request) {
                $q->whereRaw('LOWER(space_type) = ?', [strtolower($request->space_type)]);
            });
        }

        // Filter by tenant
        if ($request->has('tenant_id')) {
            $query->where('tenant_id', $request->tenant_id);
        }

        // Filter expiring soon
        if ($request->has('expiring_soon') && $request->expiring_soon) {
            $query->where('status', 'for_renewal')
                ->where('end_date', '>', Carbon::now())
                ->where('end_date', '<=', Carbon::now()->addMonths(2));
        }

        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $contracts = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $contracts
        ]);
    }

    /**
     * Store a newly created contract
     */
    public function store(Request $request)
    {
        \Log::info('ContractController@store called');
        
        // Map camelCase to snake_case (handle both frontend field name variations)
        $data = $request->all();
        
        if (isset($data['tenantId'])) {
            $data['tenant_id'] = $data['tenantId'];
            unset($data['tenantId']);
        }
        
        if (isset($data['rentalSpaceId'])) {
            $data['rental_space_id'] = $data['rentalSpaceId'];
            unset($data['rentalSpaceId']);
        }
        
        if (isset($data['startDate'])) {
            $data['start_date'] = $data['startDate'];
            unset($data['startDate']);
        }
        
        if (isset($data['durationMonths'])) {
            $data['duration_months'] = $data['durationMonths'];
            unset($data['durationMonths']);
        }
        
        // If endDate provided but no duration_months, calculate it
        if ((isset($data['endDate']) || isset($data['end_date'])) && !isset($data['duration_months'])) {
            $endDateField = isset($data['endDate']) ? $data['endDate'] : (isset($data['end_date']) ? $data['end_date'] : null);
            if ($endDateField && isset($data['start_date'])) {
                $startDate = Carbon::parse($data['start_date']);
                $endDate = Carbon::parse($endDateField);
                $data['duration_months'] = intval($startDate->diffInMonths($endDate)) + 1;
            }
        }
        
        if (isset($data['endDate'])) {
            unset($data['endDate']); // Remove endDate, we calculate it from duration
        }
        if (isset($data['end_date'])) {
            unset($data['end_date']); // Remove end_date, we calculate it from duration
        }
        
        // Handle monthlyRent/monthlyRental/monthly_rent variations (API client converts to monthly_rent)
        if (isset($data['monthlyRent'])) {
            $data['monthly_rental'] = $data['monthlyRent'];
            unset($data['monthlyRent']);
        } elseif (isset($data['monthlyRental'])) {
            $data['monthly_rental'] = $data['monthlyRental'];
            unset($data['monthlyRental']);
        } elseif (isset($data['monthly_rent'])) {
            $data['monthly_rental'] = $data['monthly_rent'];
            unset($data['monthly_rent']);
        }
        
        // Handle securityDeposit/depositAmount/security_deposit variations (API client converts to security_deposit)
        if (isset($data['securityDeposit'])) {
            $data['deposit_amount'] = $data['securityDeposit'];
            unset($data['securityDeposit']);
        } elseif (isset($data['depositAmount'])) {
            $data['deposit_amount'] = $data['depositAmount'];
            unset($data['depositAmount']);
        } elseif (isset($data['security_deposit'])) {
            $data['deposit_amount'] = $data['security_deposit'];
            unset($data['security_deposit']);
        }
        
        if (isset($data['interestRate'])) {
            $data['interest_rate'] = $data['interestRate'];
            unset($data['interestRate']);
        }
        
        // Handle terms/termsConditions/terms_conditions variations
        if (isset($data['terms'])) {
            $data['terms_conditions'] = $data['terms'];
            unset($data['terms']);
        } elseif (isset($data['termsConditions'])) {
            $data['terms_conditions'] = $data['termsConditions'];
            unset($data['termsConditions']);
        }
        
        // If tenantId (User ID) was provided, convert it to tenant_id (Tenant ID)
        if (isset($data['tenant_id']) && !is_null($data['tenant_id'])) {
            // Check if the provided tenant_id is actually a Tenant ID (exists in tenants table)
            $tenantExists = Tenant::where('id', $data['tenant_id'])->exists();
            if (!$tenantExists) {
                // It might be a User ID, try to find the associated Tenant
                $user = User::with('tenant')->find($data['tenant_id']);
                if ($user && $user->tenant) {
                    $data['tenant_id'] = $user->tenant->id;
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'Tenant profile not found for this user',
                        'errors' => ['tenant_id' => 'Tenant profile not found']
                    ], 422);
                }
            }
        }
        
        $validator = Validator::make($data, [
            'tenant_id' => 'required|exists:tenants,id',
            'rental_space_id' => 'required|exists:rental_spaces,id',
            'start_date' => 'required|date|after_or_equal:today',
            'duration_months' => 'required|integer|min:1',
            'monthly_rental' => 'required|numeric|min:0',
            'deposit_amount' => 'nullable|numeric|min:0',
            'interest_rate' => 'nullable|numeric|min:0|max:100',
            'terms_conditions' => 'nullable|string',
            'contract_file' => 'nullable|file|mimes:pdf,doc,docx|max:51200',
        ], [
            'start_date.after_or_equal' => 'The contract start date cannot be in the past.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        \Log::info('Validation passed for contract creation with:', ['data_keys' => array_keys($data)]);

        // Refresh the latest contract and space states before deciding availability.
        Contract::updateStatuses();
        RentalSpace::syncOccupancyStatuses();

        // Check if rental space is currently available according to real contract state.
        $rentalSpace = RentalSpace::find($data['rental_space_id']);
        $hasActiveOrPendingContract = Contract::where('rental_space_id', $data['rental_space_id'])
            ->whereIn('status', ['active', 'for_renewal', 'pending', 'renewed'])
            ->exists();

        $status = strtolower((string) ($rentalSpace->status ?? ''));
        $isAvailable = $rentalSpace && !in_array($status, ['occupied', 'maintenance', 'reserved', 'rented', 'terminated'], true)
            && $status !== 'unavailable'
            && !$hasActiveOrPendingContract;

        if (!$isAvailable) {
            return response()->json([
                'success' => false,
                'message' => 'Rental space is not available'
            ], 422);
        }

        // Check if tenant has existing active contract for the same space
        $existingContract = Contract::where('tenant_id', $data['tenant_id'])
            ->where('rental_space_id', $data['rental_space_id'])
            ->whereIn('status', ['active', 'for_renewal', 'renewed'])
            ->exists();

        if ($existingContract) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant already has an active contract for this space'
            ], 422);
        }

        // Calculate end date
        $startDate = Carbon::parse($data['start_date']);
        $endDate = $startDate->copy()->addMonths($data['duration_months'])->subDay();

        // Handle file upload
        $contractFile = null;
        if ($request->hasFile('contract_file')) {
            $contractFile = $request->file('contract_file')->store('contracts', 'public');
        }

        // Create contract
        \Log::info('About to create contract with data:', [
            'tenant_id' => $data['tenant_id'],
            'rental_space_id' => $data['rental_space_id'],
            'monthly_rental' => $data['monthly_rental'],
            'deposit_amount' => $data['deposit_amount'] ?? 0,
        ]);

        try {
            $contract = Contract::create([
                'contract_number' => $this->generateContractNumber(),
                'tenant_id' => $data['tenant_id'],
                'rental_space_id' => $data['rental_space_id'],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'duration_months' => $data['duration_months'],
                'monthly_rental' => $data['monthly_rental'],
                'deposit_amount' => $data['deposit_amount'] ?? 0,
                'interest_rate' => $data['interest_rate'] ?? 2, // Default 2% monthly interest
                'terms_conditions' => $data['terms_conditions'] ?? null,
                'contract_file' => $contractFile,
                'status' => 'active',
            ]);

            // A newly created contract immediately occupies its rental space.
            $rentalSpace->update(['status' => 'occupied']);

            \Log::info('Contract created successfully:', ['contract_id' => $contract->id, 'contract_number' => $contract->contract_number]);
        } catch (\Exception $e) {
            \Log::error('Failed to create contract:', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create contract',
                'error' => $e->getMessage()
            ], 500);
        }

        AuditLog::log('create', 'Contract', $contract->id, "Created contract: {$contract->contract_number}", null, $contract->toArray());

        return response()->json([
            'success' => true,
            'message' => 'Contract created successfully',
            'data' => $contract->load(['tenant.user', 'rentalSpace'])
        ], 201);
    }

    /**
     * Display the specified contract
     */
    public function show($id)
    {
        Contract::updateStatuses();

        \Log::info('ContractController@show called with ID: ' . $id);
        
        try {
            // Load contract with ALL relationships
            $contract = Contract::with([
                'tenant',           // This loads the Tenant model
                'tenant.user',      // This loads the User through Tenant
                'rentalSpace',      // This loads the RentalSpace
                'payments'          // This loads all Payments
            ])->findOrFail($id);

            \Log::info('Contract found', ['id' => $contract->id, 'number' => $contract->contract_number]);
            
            // Verify relationships are loaded
            if (!$contract->tenant) {
                \Log::warning('⚠️ Contract has no tenant relationship, tenant_id: ' . $contract->tenant_id);
            } else {
                \Log::info('✓ Tenant loaded:', [
                    'tenant_id' => $contract->tenant->id,
                    'business_name' => $contract->tenant->business_name,
                    'user_id' => $contract->tenant->user_id,
                    'user' => $contract->tenant->user ? $contract->tenant->user->name : null,
                ]);
            }
            
            if (!$contract->rentalSpace) {
                \Log::warning('⚠️ Contract has no rental space, rental_space_id: ' . $contract->rental_space_id);
            } else {
                \Log::info('✓ Rental space loaded:', [
                    'id' => $contract->rentalSpace->id,
                    'name' => $contract->rentalSpace->name,
                    'space_code' => $contract->rentalSpace->space_code,
                    'space_type' => $contract->rentalSpace->space_type,
                    'size_sqm' => $contract->rentalSpace->size_sqm,
                ]);
            }
            
            \Log::info('Payments loaded: ' . (isset($contract->payments) ? count($contract->payments) : 0) . ' payments');
            
            // Convert to array for better control of response structure
            $contractArray = $contract->toArray();
            
            // Ensure relationships are present in the array, with null checks
            $contractArray['tenant'] = $contract->tenant ? $contract->tenant->toArray() : null;
            $contractArray['rentalSpace'] = $contract->rentalSpace ? $contract->rentalSpace->toArray() : null;
            $contractArray['payments'] = $contract->payments ? $contract->payments->toArray() : [];
            
            // Also include snake_case versions for camelCase/snake_case fallbacks
            $contractArray['rental_space'] = $contractArray['rentalSpace'];
            
            \Log::info('Response structure prepared:', [
                'has_tenant' => !is_null($contractArray['tenant']),
                'has_rental_space' => !is_null($contractArray['rentalSpace']),
                'payments_count' => count($contractArray['payments']),
            ]);
            
            // Return with explicit data structure
            return response()->json([
                'success' => true,
                'data' => $contractArray
            ]);
        } catch (\Exception $e) {
            \Log::error('Contract error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            throw $e;
        }
    }

    /**
     * Update the specified contract
     */
    public function update(Request $request, $id)
    {
        $contract = Contract::findOrFail($id);
        
        \Log::info("=" . str_repeat("=", 50));
        \Log::info("UPDATE CONTRACT {$id}");
        \Log::info("=" . str_repeat("=", 50));
        
        $data = $request->all();
        \Log::info("Raw request keys: " . implode(", ", array_keys($data)));
        \Log::info("monthly_rent in request: " . ($data['monthly_rent'] ?? 'MISSING'));
        \Log::info("monthlyRent in request: " . ($data['monthlyRent'] ?? 'MISSING'));
        
        \Log::info("BEFORE: monthly_rental = {$contract->monthly_rental}");

        // Map camelCase to snake_case - IMPORTANT: apiClient sends snake_case!
        // So check for snake_case versions FIRST
        
        // Map tenant and rental space IDs
        if (isset($data['rental_space_id']) && !isset($data['rentalSpaceId'])) {
            // Already in snake_case, convert to our variable name
            $data['rental_space_id'] = $data['rental_space_id'];
        } elseif (isset($data['rentalSpaceId'])) {
            $data['rental_space_id'] = $data['rentalSpaceId'];
            unset($data['rentalSpaceId']);
        }
        
        // Handle dates
        if (isset($data['start_date']) && !isset($data['startDate'])) {
            // Already in snake_case
        } elseif (isset($data['startDate'])) {
            $data['start_date'] = $data['startDate'];
            unset($data['startDate']);
        }

        if (isset($data['start_date'])) {
            $data['start_date'] = Carbon::parse($data['start_date'])->format('Y-m-d');
        }
        
        if (isset($data['endDate'])) {
            unset($data['endDate']); // Remove, we calculate it
        }
        
        if (isset($data['duration_months']) && !isset($data['durationMonths'])) {
            // Already snake_case
        } elseif (isset($data['durationMonths'])) {
            $data['duration_months'] = $data['durationMonths'];
            unset($data['durationMonths']);
        }
        
        // Handle monthlyRent - CHECK SNAKE_CASE FIRST since apiClient converts to snake_case
        if (isset($data['monthly_rent'])) {
            $data['monthly_rental'] = floatval($data['monthly_rent']);
            \Log::info("✅ Using monthly_rent from request: {$data['monthly_rental']}");
        } elseif (isset($data['monthlyRent'])) {
            $data['monthly_rental'] = floatval($data['monthlyRent']);
            \Log::info("✅ Using monthlyRent from request: {$data['monthly_rental']}");
        } elseif (isset($data['monthly_rental'])) {
            $data['monthly_rental'] = floatval($data['monthly_rental']);
            \Log::info("✅ Using monthly_rental from request: {$data['monthly_rental']}");
        } else {
            \Log::warning("❌ No monthly_rent/monthlyRent/monthly_rental found in request!");
        }
        
        // Clean up monthly_rent and monthlyRent variants
        unset($data['monthly_rent']);
        unset($data['monthlyRent']);
        unset($data['monthlyRental']);
        
        // Handle security deposit - CHECK SNAKE_CASE FIRST
        if (isset($data['deposit_amount'])) {
            // Already snake_case, keep it
        } elseif (isset($data['securityDeposit'])) {
            $data['deposit_amount'] = $data['securityDeposit'];
            unset($data['securityDeposit']);
        } elseif (isset($data['depositAmount'])) {
            $data['deposit_amount'] = $data['depositAmount'];
            unset($data['depositAmount']);
        }
        
        unset($data['securityDeposit']);
        unset($data['depositAmount']);
        
        if (isset($data['interest_rate'])) {
            // OK, already snake_case
        } elseif (isset($data['interestRate'])) {
            $data['interest_rate'] = $data['interestRate'];
            unset($data['interestRate']);
        }
        
        unset($data['interestRate']);
        
        // Handle terms - CHECK SNAKE_CASE FIRST
        if (isset($data['terms_conditions'])) {
            // Already snake_case
        } elseif (isset($data['terms'])) {
            $data['terms_conditions'] = $data['terms'];
            unset($data['terms']);
        } elseif (isset($data['termsConditions'])) {
            $data['terms_conditions'] = $data['termsConditions'];
            unset($data['termsConditions']);
        }
        
        unset($data['terms']);
        unset($data['termsConditions']);
        
        if (isset($data['contractFile'])) {
            $data['contract_file'] = $data['contractFile'];
            unset($data['contractFile']);
        }
        
        unset($data['contract_file']);

        $validator = Validator::make($data, [
            'tenant_id' => 'sometimes|integer|exists:tenants,id',
            'rental_space_id' => 'sometimes|integer|exists:rental_spaces,id',
            'start_date' => 'sometimes|date',
            'duration_months' => 'sometimes|integer|min:1',
            'monthly_rental' => 'sometimes|numeric|min:0',
            'deposit_amount' => 'sometimes|numeric|min:0',
            'interest_rate' => 'sometimes|numeric|min:0|max:100',
            'terms_conditions' => 'sometimes|string',
            'status' => 'sometimes|in:active,expired,terminated,pending,for_renewal,renewed',
        ]);

        if ($validator->fails()) {
            \Log::warning("Validation failed: " . json_encode($validator->errors()));
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $oldValues = $contract->toArray();

        // Handle file upload
        if ($request->hasFile('contract_file')) {
            if ($contract->contract_file) {
                Storage::disk('public')->delete($contract->contract_file);
            }
            $contract->contract_file = $request->file('contract_file')->store('contracts', 'public');
            $data['contract_file'] = $contract->contract_file;
        }

        // Recalculate end date if start date or duration changed
        if (isset($data['start_date']) || isset($data['duration_months'])) {
            $startDate = isset($data['start_date']) ? Carbon::parse($data['start_date']) : $contract->start_date;
            $duration = isset($data['duration_months']) ? $data['duration_months'] : $contract->duration_months;
            $data['end_date'] = $startDate->copy()->addMonths($duration)->subDay();
        }
        
        // Also add tenant_id if not present (apiClient converts rentalSpaceId to rental_space_id)
        if (isset($data['tenant_id'])) {
            // good
        } elseif (isset($data['tenantId'])) {
            $data['tenant_id'] = $data['tenantId'];
        }
        unset($data['tenantId']);

        // Prepare update data - ONLY these fields are allowed in database
        $updateData = [];
        $allowedFields = [
            'tenant_id', 'rental_space_id', 'start_date', 'end_date', 'duration_months',
            'monthly_rental', 'deposit_amount', 'interest_rate', 'terms_conditions', 'contract_file', 'status'
        ];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }
        
        \Log::info("ALL data keys after mapping: " . implode(", ", array_keys($data)));
        \Log::info("UPDATE data to send to DB: " . implode(", ", array_keys($updateData)));
        \Log::info("monthly_rental value: " . ($updateData['monthly_rental'] ?? 'NOT IN UPDATE DATA'));
        
        try {
            // Execute update
            $result = Contract::whereId($id)->update($updateData);
            
            \Log::info("UPDATE EXECUTED - Rows affected: {$result}");
            
            // Verify immediately
            $check = Contract::find($id);
            \Log::info("VERIFICATION - monthly_rental in DB now: {$check->monthly_rental}");
            
            // Reload fresh contract from database
            $contract = Contract::with(['tenant.user', 'rentalSpace', 'payments'])->findOrFail($id);
            
            \Log::info("RESPONSE - monthly_rental: {$contract->monthly_rental}");
            \Log::info("=" . str_repeat("=", 50));
            
            AuditLog::log('update', 'Contract', $contract->id, "Updated contract: {$contract->contract_number}", $oldValues, $contract->toArray());
            
            return response()->json([
                'success' => true,
                'message' => 'Contract updated successfully',
                'data' => $contract
            ]);
        } catch (\Exception $e) {
            \Log::error("UPDATE ERROR: " . $e->getMessage());
            \Log::error("Trace: " . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Activate contract
     */
    public function activate($id)
    {
        try {
            $contract = Contract::with('rentalSpace')->findOrFail($id);

            \Log::info("=== ACTIVATE CONTRACT {$id} ===");
            \Log::info("Contract ID: {$contract->id}, Rental Space ID: {$contract->rental_space_id}, Status: {$contract->status}");

            // Allow activation only for pending contracts (not active, for_renewal, expired, terminated)
            $inactiveStatuses = ['active', 'for_renewal', 'expired', 'terminated', 'renewed'];
            if (in_array(strtolower($contract->status), array_map('strtolower', $inactiveStatuses))) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot activate contract with status: {$contract->status}.",
                    'current_status' => $contract->status
                ], 422);
            }

            $oldStatus = $contract->status;
            
            // Check if contract is already within 2 months of expiry
            if ($contract->end_date && $contract->end_date->lte(Carbon::now()->addMonths(2)) && $contract->end_date->gt(Carbon::now())) {
                $contract->status = 'for_renewal';
            } elseif ($contract->end_date && $contract->end_date->lte(Carbon::now())) {
                $contract->status = 'expired';
            } else {
                $contract->status = 'active';
            }
            $contract->save();

            \Log::info("Contract status updated to {$contract->status}");

            // Update rental space status to occupied
            if ($contract->rental_space_id) {
                \Log::info("Updating rental space {$contract->rental_space_id}...");
                
                // Try Eloquent update first
                if ($contract->rentalSpace) {
                    $contract->rentalSpace->status = 'occupied';
                    $contract->rentalSpace->save();
                    \Log::info("Eloquent update: Rental space {$contract->rental_space_id} status changed to 'occupied'");
                }
                
                // Also do a direct database update to be sure
                $updated = \DB::table('rental_spaces')
                    ->where('id', $contract->rental_space_id)
                    ->update(['status' => 'occupied']);
                
                \Log::info("Direct DB update result: " . ($updated ? "Success ({$updated} rows)" : "No rows updated"));
                
                // Verify the update
                $verify = \DB::table('rental_spaces')->where('id', $contract->rental_space_id)->first();
                \Log::info("Verification - Rental space status is now: " . ($verify ? $verify->status : 'NULL'));
                
            } else {
                \Log::error("Contract {$id} has no rental_space_id!");
            }

            // Payment schedule generation is intentionally disabled for now.
            // Contracts are activated without creating payment rows automatically.
            \Log::info("Contract activated without automatic payment schedule generation");

            // Create activation notification for admin/staff/cashier
            $contract->createActivationNotification();

            AuditLog::log('update', 'Contract', $contract->id, "Activated contract: {$contract->contract_number}", ['status' => $oldStatus], ['status' => 'active']);

            // Refresh to get latest data
            $contract->refresh();
            
            return response()->json([
                'success' => true,
                'message' => 'Contract activated successfully',
                'data' => $contract->load(['tenant.user', 'rentalSpace', 'payments'])
            ]);
        } catch (\Exception $e) {
            \Log::error("Error activating contract {$id}: " . $e->getMessage());
            \Log::error($e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Error activating contract: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Terminate contract
     */
    public function terminate(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $contract = Contract::with('rentalSpace')->findOrFail($id);

        \Log::info("=== TERMINATE CONTRACT {$id} ===");
        \Log::info("Contract ID: {$contract->id}, Rental Space ID: {$contract->rental_space_id}, Status: {$contract->status}");

        if ($contract->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Only active contracts can be terminated'
            ], 422);
        }

        $oldStatus = $contract->status;
        $contract->status = 'terminated';
        $contract->terms_conditions .= "\n\nTermination Reason: " . $request->reason;
        $contract->save();

        \Log::info("Contract status updated to terminated");

        // Update rental space status to available
        if ($contract->rental_space_id) {
            \Log::info("Updating rental space {$contract->rental_space_id}...");
            
            // Try Eloquent update first
            if ($contract->rentalSpace) {
                $contract->rentalSpace->status = 'available';
                $contract->rentalSpace->save();
                \Log::info("Eloquent update: Rental space {$contract->rental_space_id} status changed to 'available'");
            }
            
            // Also do a direct database update to be sure
            $updated = \DB::table('rental_spaces')
                ->where('id', $contract->rental_space_id)
                ->update(['status' => 'available']);
            
            \Log::info("Direct DB update result: " . ($updated ? "Success ({$updated} rows)" : "No rows updated"));
            
            // Verify the update
            $verify = \DB::table('rental_spaces')->where('id', $contract->rental_space_id)->first();
            \Log::info("Verification - Rental space status is now: " . ($verify ? $verify->status : 'NULL'));
            
        } else {
            \Log::error("Contract {$id} has no rental_space_id!");
        }

        // Create notification for tenant
        $tenant = $contract->tenant;
        Notification::create([
            'user_id' => $tenant->user_id,
            'type' => 'contract_terminated',
            'title' => 'Contract Terminated',
            'message' => "Your contract {$contract->contract_number} has been terminated. Reason: {$request->reason}",
            'data' => ['contract_id' => $contract->id],
        ]);

        AuditLog::log('update', 'Contract', $contract->id, "Terminated contract: {$contract->contract_number}. Reason: {$request->reason}", ['status' => $oldStatus], ['status' => 'terminated']);

        // Refresh relationships to get latest data
        $contract->refresh();
        
        return response()->json([
            'success' => true,
            'message' => 'Contract terminated successfully',
            'data' => $contract->load(['tenant.user', 'rentalSpace'])
        ]);
    }

    /**
     * Delete contract
     */
    public function destroy($id)
    {
        $contract = Contract::findOrFail($id);
        $contractNumber = $contract->contract_number;

        $contract->payments()->delete();

        if ($contract->rental_space_id) {
            $hasOtherActiveContract = Contract::withTrashed()
                ->where('rental_space_id', $contract->rental_space_id)
                ->where('id', '!=', $contract->id)
                ->whereIn('status', ['active', 'for_renewal', 'pending', 'renewed'])
                ->exists();

            if (!$hasOtherActiveContract && $contract->rentalSpace) {
                $contract->rentalSpace->update(['status' => 'available']);
            }
        }

        // Delete contract file
        if ($contract->contract_file) {
            Storage::disk('public')->delete($contract->contract_file);
        }

        AuditLog::log('delete', 'Contract', $contract->id, "Deleted contract: {$contractNumber}");

        $contract->forceDelete();
        RentalSpace::syncOccupancyStatuses();

        return response()->json([
            'success' => true,
            'message' => 'Contract deleted successfully'
        ]);
    }

    /**
     * Renew contract
     */
    public function renew(Request $request, $id)
    {
        $oldContract = Contract::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'duration_months' => 'required|integer|min:1',
            'monthly_rental' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        if (!in_array($oldContract->status, ['active', 'for_renewal'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Only active or for-renewal contracts can be renewed.',
                'current_status' => $oldContract->status,
            ], 422);
        }

        $startDate = Carbon::now();
        $endDate = $startDate->copy()->addMonths($request->duration_months)->subDay();

        $oldContract->start_date = $startDate;
        $oldContract->end_date = $endDate;
        $oldContract->duration_months = $request->duration_months;
        $oldContract->monthly_rental = $request->monthly_rental ?? $oldContract->monthly_rental;
        $oldContract->status = 'renewed';
        $oldContract->save();

        // No automatic payment schedule generation on renewal.

        // Notify the tenant and dashboard users about the renewal.
        $recipientIds = User::query()
            ->where(function ($query) {
                $query->whereIn('role', ['admin', 'staff', 'cashier'])
                    ->orWhereIn('role', ['ADMIN', 'STAFF', 'CASHIER']);
            })
            ->pluck('id')
            ->push($oldContract->tenant->user_id)
            ->unique();

        foreach ($recipientIds as $recipientId) {
            Notification::create([
                'user_id' => $recipientId,
                'type' => 'contract_renewed',
                'title' => 'Contract Renewed',
                'message' => "Contract {$oldContract->contract_number} was renewed. New renewal period runs until {$oldContract->end_date->format('Y-m-d')}",
                'data' => ['contract_id' => $oldContract->id],
            ]);
        }

        AuditLog::log('update', 'Contract', $oldContract->id, "Renewed contract {$oldContract->contract_number}. Updated period to {$oldContract->start_date->format('Y-m-d')} through {$oldContract->end_date->format('Y-m-d')}");

        return response()->json([
            'success' => true,
            'message' => 'Contract renewed successfully',
            'data' => $oldContract->load(['tenant.user', 'rentalSpace'])
        ], 200);
    }

    /**
     * Generate and get QR code for contract
     */
    public function getQRCode($id)
    {
        try {
            $contract = Contract::with(['tenant.user', 'rentalSpace'])->findOrFail($id);

            // Create QR code data - point directly to the lease PDF using network IP (for phone scanning)
            $qrBaseUrl = env('QR_BASE_URL', config('app.url'));
            $qrData = $qrBaseUrl . '/api/contracts/' . $contract->id . '/lease';

            // Generate QR code
            $qrCode = new QrCode($qrData);
            $writer = new SvgWriter();
            $qrSvg = $writer->write($qrCode);
            
            // Convert SVG to string
            $svgString = $qrSvg->getString();
            
            // Convert to data URI
            $dataUri = 'data:image/svg+xml;base64,' . base64_encode($svgString);

            // Return QR code data URI
            return response()->json([
                'success' => true,
                'qrCode' => $dataUri,
                'data' => [
                    'contract_id' => $contract->id,
                    'contract_number' => $contract->contract_number,
                    'tenant' => $contract->tenant->contact_person,
                    'rental_space' => $contract->rentalSpace->space_code,
                    'start_date' => $contract->start_date,
                    'end_date' => $contract->end_date,
                    'monthly_rental' => $contract->monthly_rental,
                    'status' => $contract->status,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate QR code: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Public endpoint to view contract details via QR code
     * No authentication required
     */
    public function viewQRContract($id)
    {
        try {
            \Log::info('viewQRContract called for ID: ' . $id);
            
            $contract = Contract::with([
                'tenant.user',
                'rentalSpace'
            ])->findOrFail($id);

            \Log::info('Contract found', [
                'id' => $contract->id,
                'has_tenant' => !is_null($contract->tenant),
                'has_space' => !is_null($contract->rentalSpace)
            ]);

            // Return contract details in camelCase for frontend
            // Safely handle null tenant and rental space relationships
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $contract->id,
                    'contractNumber' => $contract->contract_number,
                    'tenantId' => $contract->tenant_id,
                    'tenant' => $contract->tenant ? [
                        'id' => $contract->tenant->id,
                        'contactPerson' => $contract->tenant->contact_person,
                        'businessName' => $contract->tenant->business_name,
                        'contactNumber' => $contract->tenant->contact_number,
                    ] : null,
                    'rentalSpaceId' => $contract->rental_space_id,
                    'rentalSpace' => $contract->rentalSpace ? [
                        'id' => $contract->rentalSpace->id,
                        'spaceCode' => $contract->rentalSpace->space_code,
                        'name' => $contract->rentalSpace->name,
                        'spaceType' => $contract->rentalSpace->space_type,
                        'sizeSqm' => $contract->rentalSpace->size_sqm,
                    ] : null,
                    'startDate' => $contract->start_date?->format('Y-m-d'),
                    'endDate' => $contract->end_date?->format('Y-m-d'),
                    'monthlyRental' => $contract->monthly_rental,
                    'securityDeposit' => $contract->deposit_amount,
                    'status' => $contract->status,
                    'terms' => $contract->terms,
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('viewQRContract error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Contract not found'
            ], 404);
        }
    }

    /**
     * Download lease PDF for a contract
     */
    public function downloadLease($id)
    {
        try {
            $contract = Contract::with(['tenant.user', 'rentalSpace'])->findOrFail($id);

            // Prepare data for the lease document
            $data = [
                'contractNumber' => $contract->contract_number,
                'contractDate' => $contract->created_at->format('F d, Y'),
                'startDate' => $contract->start_date->format('F d, Y'),
                'endDate' => $contract->end_date->format('F d, Y'),
                'tenantName' => $contract->tenant->contact_person,
                'tenantCompany' => $contract->tenant->business_name,
                'tenantAddress' => $contract->tenant->business_address ?? 'Not provided',
                'tenantPhone' => $contract->tenant->contact_number,
                'spaceName' => $contract->rentalSpace->name,
                'spaceCode' => $contract->rentalSpace->space_code,
                'spaceType' => $contract->rentalSpace->space_type,
                'spaceSqm' => $contract->rentalSpace->size_sqm ?? 'N/A',
                'monthlyRent' => number_format($contract->monthly_rental, 2),
                'securityDeposit' => number_format($contract->deposit_amount ?? 0, 2),
                'terms' => $contract->terms ?? 'Standard lease terms apply. Tenant agrees to maintain the premises in good condition and pay rent on time.',
                'totalDurationMonths' => $contract->start_date->diffInMonths($contract->end_date),
            ];

            // Generate PDF
            $pdf = \PDF::loadView('contracts.lease', $data);
            
            // Return PDF to view in browser (for QR scanning on mobile)
            return $pdf->stream('lease-' . $contract->contract_number . '.pdf');
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate lease: ' . $e->getMessage()
            ], 500);
        }
    }
}
