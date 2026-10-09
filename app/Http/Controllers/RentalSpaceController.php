<?php

namespace App\Http\Controllers;

use App\Models\RentalSpace;
use App\Models\AuditLog;
use App\Services\CloudinaryService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class RentalSpaceController extends Controller
{
    public function __construct(private CloudinaryService $cloudinary)
    {
    }

    private function withMapImageUrl(RentalSpace $space): RentalSpace
    {
        $image = (string) $space->map_image;
        $space->setAttribute(
            'map_image_url',
            str_starts_with($image, 'rental-spaces/')
                ? $this->cloudinary->generateUrl($image, 1200, 900)
                : $image
        );

        return $space;
    }

    private function uploadMapImage(UploadedFile $file): array
    {
        $result = $this->cloudinary->uploadFile(
            $file,
            'rental-spaces',
            'space-' . Str::uuid()
        );

        if (!$result['success']) {
            \Log::error('Rental space image upload to Cloudinary failed', [
                'reason' => $result['message'] ?? 'Unknown Cloudinary error',
            ]);
        }

        return $result;
    }

    private function deleteMapImage(?string $image): void
    {
        if (!$image) {
            return;
        }

        if (
            str_starts_with($image, 'rental-spaces/')
            && !Storage::disk('public')->exists($image)
        ) {
            if (!$this->cloudinary->deleteFile($image)) {
                \Log::warning('Failed to delete old rental space image from Cloudinary', [
                    'public_id' => $image,
                ]);
            }

            return;
        }

        Storage::disk('public')->delete($image);
    }

    /**
     * Display a listing of rental spaces
     */
    public function index(Request $request)
    {
        \App\Models\Contract::updateStatuses();

        // Load with relationship and count active / renewed contracts
        $query = RentalSpace::with(['contracts' => function ($q) {
            $q->whereIn('status', ['active', 'for_renewal', 'pending', 'renewed']);
        }])->withCount(['contracts as active_contracts_count' => function ($q) {
            $q->whereIn('status', ['active', 'for_renewal', 'pending', 'renewed']);
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

        $statusFilter = $request->has('status') ? strtolower((string) $request->status) : null;

        // Default to only available spaces for selection forms like Create Contract.
        // This avoids showing occupied or reserved units unless the caller explicitly asks for all data.
        if (!$request->boolean('include_all') && empty($statusFilter)) {
            $query->where(function ($q) {
                    $q->where('status', 'available')
                      ->orWhereNull('status')
                      ->orWhere('status', '');
                })
                ->whereDoesntHave('contracts', function ($q) {
                    $q->whereIn('status', ['active', 'for_renewal', 'pending', 'renewed']);
                });
        }

        // Filter by status (but also consider active contracts)
        if ($statusFilter) {
            if ($statusFilter === 'all') {
                // Explicitly allow all spaces when caller asks for them.
            } elseif ($statusFilter === 'occupied') {
                // Show spaces with active, pending, for_renewal, or renewed contracts
                $query->whereHas('contracts', function ($q) {
                    $q->whereIn('status', ['active', 'for_renewal', 'pending', 'renewed']);
                });
            } elseif ($statusFilter === 'available') {
                // Show spaces without any active/renewal/pending contract in force
                $query->where(function ($q) {
                    $q->where('status', 'available')
                        ->orWhereNull('status')
                        ->orWhere('status', '');
                })->whereDoesntHave('contracts', function ($q) {
                    $q->whereIn('status', ['active', 'for_renewal', 'pending', 'renewed']);
                });
            } else {
                // Filter by the status field
                $query->where('status', $statusFilter);
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
            $this->withMapImageUrl($space);
            
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
            'map_image' => 'nullable|file|mimes:jpeg,png,jpg,gif,webp|max:10240',
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

        $mapImage = null;
        if ($request->hasFile('map_image')) {
            $upload = $this->uploadMapImage($request->file('map_image'));
            if (!$upload['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rental space image upload failed. Check the Cloudinary configuration and try again.',
                ], 502);
            }
            $mapImage = $upload['public_id'];
        }

        try {
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
        } catch (Throwable $error) {
            if ($mapImage) {
                $this->deleteMapImage($mapImage);
            }

            throw $error;
        }

        AuditLog::log('create', 'RentalSpace', $space->id, "Created rental space: {$space->name}", null, $space->toArray());
        $this->withMapImageUrl($space);

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
        $this->withMapImageUrl($space);

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
            'status' => 'sometimes|in:available,occupied,maintenance,inactive',
            'map_image' => 'nullable|file|mimes:jpeg,png,jpg,gif,webp|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $oldValues = $space->toArray();

        $newMapImage = null;
        if ($request->hasFile('map_image')) {
            $upload = $this->uploadMapImage($request->file('map_image'));
            if (!$upload['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rental space image upload failed. Check the Cloudinary configuration and try again.',
                ], 502);
            }
            $newMapImage = $upload['public_id'];
        }

        $oldMapImage = $space->map_image;
        try {
            if ($newMapImage) {
                $space->map_image = $newMapImage;
            }
            $space->fill($request->except('map_image'))->save();
        } catch (Throwable $error) {
            if ($newMapImage) {
                $this->deleteMapImage($newMapImage);
            }

            throw $error;
        }
        if ($newMapImage) {
            $this->deleteMapImage($oldMapImage);
        }

        AuditLog::log('update', 'RentalSpace', $space->id, "Updated rental space: {$space->name}", $oldValues, $space->toArray());
        $this->withMapImageUrl($space);

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

        $this->deleteMapImage($space->map_image);

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
            // Refresh status before filtering so the dropdown reflects the real current state.
            \App\Models\Contract::updateStatuses();
            RentalSpace::syncOccupancyStatuses();

            // Only spaces that are truly available should be returned. A space is unavailable if
            // it has an active/for_renewal/pending contract or if it is explicitly marked as occupied,
            // maintenance, reserved, rented, terminated, or unavailable.
            $query = RentalSpace::where(function ($q) {
                    $q->where('status', 'available')
                      ->orWhereNull('status')
                      ->orWhere('status', '');
                })
                ->whereNotIn('status', ['occupied', 'maintenance', 'reserved', 'rented', 'terminated', 'unavailable'])
                ->whereDoesntHave('contracts', function ($q) {
                    $q->whereIn('status', ['active', 'for_renewal', 'pending', 'renewed']);
                })
                ->with(['contracts' => function ($q) {
                    $q->whereIn('status', ['active', 'for_renewal', 'pending', 'renewed']);
                }]);

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
        // Count spaces without active/for_renewal contracts
        $availableSpacesCount = RentalSpace::available()->count();
        
        // Count spaces with active contracts
        $occupiedSpacesCount = RentalSpace::where('status', 'occupied')
            ->orWhereHas('contracts', function ($q) {
                $q->whereIn('status', ['active', 'for_renewal', 'pending', 'renewed']);
            })->count();
        
        $stats = [
            'total_spaces' => RentalSpace::count(),
            'available_spaces' => $availableSpacesCount,
            'occupied_spaces' => $occupiedSpacesCount,
            'maintenance_spaces' => RentalSpace::where('status', 'maintenance')->count(),
            'by_type' => [
                'food_stall' => [
                    'total' => RentalSpace::where('space_type', 'food_stall')->count(),
                    'available' => RentalSpace::available()->where('space_type', 'food_stall')->count(),
                    'occupied' => RentalSpace::where('space_type', 'food_stall')->where('status', 'occupied')->count(),
                ],
                'market_hall' => [
                    'total' => RentalSpace::where('space_type', 'market_hall')->count(),
                    'available' => RentalSpace::available()->where('space_type', 'market_hall')->count(),
                    'occupied' => RentalSpace::where('space_type', 'market_hall')->where('status', 'occupied')->count(),
                ],
                'banera_warehouse' => [
                    'total' => RentalSpace::where('space_type', 'banera_warehouse')->count(),
                    'available' => RentalSpace::available()->where('space_type', 'banera_warehouse')->count(),
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
