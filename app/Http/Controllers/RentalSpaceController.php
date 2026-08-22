<?php

namespace App\Http\Controllers;

use App\Models\RentalSpace;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class RentalSpaceController extends Controller
{
    /**
     * Display a listing of rental spaces
     */
    public function index(Request $request)
    {
        // Load with relationship and count active contracts
        $query = RentalSpace::with(['contracts' => function ($q) {
            $q->where('status', 'active');
        }])->withCount(['contracts as active_contracts_count' => function ($q) {
            $q->where('status', 'active');
        }]);

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('space_code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Filter by space type
        if ($request->has('space_type')) {
            $query->where('space_type', $request->space_type);
        }

        // Filter by status (but also consider active contracts)
        if ($request->has('status')) {
            $status = strtolower($request->status);
            if ($status === 'occupied') {
                // Show spaces with active contracts
                $query->whereHas('contracts', function ($q) {
                    $q->where('status', 'active');
                });
            } elseif ($status === 'available') {
                // Show spaces without active contracts
                $query->whereDoesntHave('contracts', function ($q) {
                    $q->where('status', 'active');
                });
            } else {
                // Filter by the status field
                $query->where('status', $status);
            }
        }

        // Sort
        $sortBy = $request->get('sort_by', 'space_code');
        $sortOrder = $request->get('sort_order', 'asc');
        $query->orderBy($sortBy, $sortOrder);

        $spaces = $query->paginate($request->get('per_page', 1000));
        
        // Map the response to include computed occupancy status
        $spaces->getCollection()->transform(function ($space) {
            $space->is_occupied = $space->active_contracts_count > 0;
            $space->occupancy_status = $space->active_contracts_count > 0 ? 'occupied' : 'available';
            
            // DEBUG: Log first few spaces
            static $logCount = 0;
            if ($logCount < 3) {
                \Log::info('🔍 Space ' . $space->id . ' - Active Contracts Count: ' . $space->active_contracts_count . 
                          ', Occupancy Status: ' . $space->occupancy_status . 
                          ', DB Status: ' . $space->status);
                $logCount++;
            }
            
            return $space;
        });

        AuditLog::log('view', 'RentalSpace', null, 'Viewed rental space list');

        return response()->json([
            'success' => true,
            'data' => $spaces
        ]);
    }

    /**
     * Store a newly created rental space
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'space_type' => 'required|in:food_stall,market_hall,banera_warehouse',
            'name' => 'required|string|max:255',
            'size_sqm' => 'required|numeric|min:0',
            'description' => 'nullable|string',
            'base_rental_rate' => 'required|numeric|min:0',
            'map_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Generate space code
        $typePrefix = match($request->space_type) {
            'food_stall' => 'FS',
            'market_hall' => 'MH',
            'banera_warehouse' => 'BW',
        };

        $count = RentalSpace::where('space_type', $request->space_type)->count() + 1;
        $spaceCode = $typePrefix . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);

        // Handle map image upload
        $mapImage = null;
        if ($request->hasFile('map_image')) {
            $mapImage = $request->file('map_image')->store('rental_spaces', 'public');
        }

        $space = RentalSpace::create([
            'space_code' => $spaceCode,
            'space_type' => $request->space_type,
            'name' => $request->name,
            'size_sqm' => $request->size_sqm,
            'description' => $request->description,
            'map_image' => $mapImage,
            'base_rental_rate' => $request->base_rental_rate,
            'status' => 'available',
        ]);

        AuditLog::log('create', 'RentalSpace', $space->id, "Created rental space: {$space->name}", null, $space->toArray());

        return response()->json([
            'success' => true,
            'message' => 'Rental space created successfully',
            'data' => $space
        ], 201);
    }

    /**
     * Display the specified rental space
     */
    public function show($id)
    {
        $space = RentalSpace::with([
            'contracts.tenant.user',
            'activeContract.tenant.user',
            'currentTenant'
        ])->findOrFail($id);

        AuditLog::log('view', 'RentalSpace', $space->id, "Viewed rental space: {$space->name}");

        return response()->json([
            'success' => true,
            'data' => $space
        ]);
    }

    /**
     * Update the specified rental space
     */
    public function update(Request $request, $id)
    {
        $space = RentalSpace::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'size_sqm' => 'sometimes|numeric|min:0',
            'description' => 'sometimes|string',
            'base_rental_rate' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|in:available,occupied,maintenance',
            'map_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $oldValues = $space->toArray();

        // Handle map image upload
        if ($request->hasFile('map_image')) {
            // Delete old image
            if ($space->map_image) {
                Storage::disk('public')->delete($space->map_image);
            }
            $space->map_image = $request->file('map_image')->store('rental_spaces', 'public');
        }

        $space->fill($request->except('map_image'))->save();

        AuditLog::log('update', 'RentalSpace', $space->id, "Updated rental space: {$space->name}", $oldValues, $space->toArray());

        return response()->json([
            'success' => true,
            'message' => 'Rental space updated successfully',
            'data' => $space
        ]);
    }

    /**
     * Remove the specified rental space
     */
    public function destroy($id)
    {
        $space = RentalSpace::findOrFail($id);

        // Check if space has active contracts
        if ($space->activeContract()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete rental space with active contract'
            ], 422);
        }

        $spaceName = $space->name;

        // Delete map image
        if ($space->map_image) {
            Storage::disk('public')->delete($space->map_image);
        }

        AuditLog::log('delete', 'RentalSpace', $space->id, "Deleted rental space: {$spaceName}");

        $space->delete();

        return response()->json([
            'success' => true,
            'message' => 'Rental space deleted successfully'
        ]);
    }

    /**
     * Get available rental spaces (without active contracts)
     * 
     * This endpoint returns only rental spaces that are NOT currently occupied.
     * A rental space is considered occupied if it has a contract with status='active'.
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAvailableSpaces(Request $request)
    {
        try {
            // Only spaces marked available and without active or pending contracts
            $query = RentalSpace::where('status', 'available')
                ->whereDoesntHave('contracts', function ($q) {
                    $q->whereIn('status', ['active', 'pending']);
                })
                ->with('contracts');

            // Optional: filter by space type
            if ($request->has('space_type')) {
                $query->where('space_type', $request->space_type);
            }

            // Paginate or get all
            $perPage = (int)$request->get('per_page', 1000);
            $spaces = $query->orderBy('space_code')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $spaces
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching available spaces: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch available spaces',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get rental space statistics
     */
    public function getStatistics()
    {
        // Count spaces without active contracts
        $availableSpacesCount = RentalSpace::where('status', 'available')
            ->whereDoesntHave('contracts', function ($q) {
                $q->where('status', 'active');
            })->count();
        
        // Count spaces with active contracts
        $occupiedSpacesCount = RentalSpace::where('status', 'occupied')
            ->orWhereHas('contracts', function ($q) {
                $q->where('status', 'active');
            })->count();
        
        $stats = [
            'total_spaces' => RentalSpace::count(),
            'available_spaces' => $availableSpacesCount,
            'occupied_spaces' => $occupiedSpacesCount,
            'maintenance_spaces' => RentalSpace::where('status', 'maintenance')->count(),
            'by_type' => [
                'food_stall' => [
                    'total' => RentalSpace::where('space_type', 'food_stall')->count(),
                    'available' => RentalSpace::where('space_type', 'food_stall')
                        ->whereDoesntHave('contracts', function ($q) {
                            $q->where('status', 'active');
                        })->count(),
                    'occupied' => RentalSpace::where('space_type', 'food_stall')->where('status', 'occupied')->count(),
                ],
                'market_hall' => [
                    'total' => RentalSpace::where('space_type', 'market_hall')->count(),
                    'available' => RentalSpace::where('space_type', 'market_hall')
                        ->whereDoesntHave('contracts', function ($q) {
                            $q->where('status', 'active');
                        })->count(),
                    'occupied' => RentalSpace::where('space_type', 'market_hall')->where('status', 'occupied')->count(),
                ],
                'banera_warehouse' => [
                    'total' => RentalSpace::where('space_type', 'banera_warehouse')->count(),
                    'available' => RentalSpace::where('space_type', 'banera_warehouse')
                        ->whereDoesntHave('contracts', function ($q) {
                            $q->where('status', 'active');
                        })->count(),
                    'occupied' => RentalSpace::where('space_type', 'banera_warehouse')->where('status', 'occupied')->count(),
                ],
            ],
            'occupancy_rate' => RentalSpace::count() > 0 
                ? round((RentalSpace::where('status', 'occupied')->count() / RentalSpace::count()) * 100, 2)
                : 0,
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}
