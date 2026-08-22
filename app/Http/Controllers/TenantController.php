<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Models\AuditLog;
use App\Services\CloudinaryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class TenantController extends Controller
{
    protected $cloudinary;

    public function __construct(CloudinaryService $cloudinary)
    {
        $this->cloudinary = $cloudinary;
    }
    /**
     * Display a listing of tenants
     */
    public function index(Request $request)
    {
        $query = Tenant::with('user');

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('tenant_code', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('contact_number', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $tenants = $query->paginate($request->get('per_page', 15));

        // Transform items with profile picture URLs
        $items = $tenants->getCollection()->map(function ($tenant) {
            $tenantArray = $tenant->toArray();
            if ($tenant->profile_picture) {
                try {
                    // Generate Cloudinary URL from public_id
                    $url = $this->cloudinary->generateUrl($tenant->profile_picture);
                    $tenantArray['profile_picture_url'] = $url;
                    $tenantArray['profilePicture'] = $url;
                } catch (\Exception $e) {
                    \Log::warning('Failed to generate Cloudinary URL', [
                        'tenant_id' => $tenant->id,
                        'public_id' => $tenant->profile_picture,
                        'error' => $e->getMessage()
                    ]);
                    // Leave URL fields as null if Cloudinary fails
                    $tenantArray['profile_picture_url'] = null;
                    $tenantArray['profilePicture'] = null;
                }
            }
            return $tenantArray;
        })->all();

        try {
            AuditLog::log('view', 'Tenant', null, 'Viewed tenant list');
        } catch (\Exception $e) {
            \Log::warning('Failed to log audit', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'data' => array_merge(
                $tenants->toArray(),
                ['data' => $items]
            )
        ]);
    }

    /**
     * Store a newly created tenant
     */
    public function store(Request $request)
    {
        // Log incoming request for debugging
        \Log::info('Tenant store request received', [
            'raw_input' => $request->all(),
            'headers' => $request->headers->all()
        ]);

        // Map frontend field names (camelCase) to backend field names (snake_case)
        $data = $request->all();
        
        // Map camelCase to snake_case for all fields
        if (isset($data['firstName']) && !isset($data['first_name'])) {
            $data['first_name'] = $data['firstName'];
        }
        if (isset($data['lastName']) && !isset($data['last_name'])) {
            $data['last_name'] = $data['lastName'];
        }
        if (isset($data['businessName']) && !isset($data['business_name'])) {
            $data['business_name'] = $data['businessName'];
        }
        if (isset($data['businessType']) && !isset($data['business_type'])) {
            $data['business_type'] = $data['businessType'];
        }
        if (isset($data['businessAddress']) && !isset($data['business_address'])) {
            $data['business_address'] = $data['businessAddress'];
        }
        if (isset($data['contactPerson']) && !isset($data['contact_person'])) {
            $data['contact_person'] = $data['contactPerson'];
        }
        if (isset($data['contactNumber']) && !isset($data['contact_number'])) {
            $data['contact_number'] = $data['contactNumber'];
        }

        // Map contactNumber to phone for user table
        if (isset($data['contactNumber']) && !isset($data['phone'])) {
            $data['phone'] = $data['contactNumber'];
        }
        
        // Combine firstName and lastName into name field
        $firstName = $data['first_name'] ?? $data['firstName'] ?? '';
        $lastName = $data['last_name'] ?? $data['lastName'] ?? '';
        $data['name'] = trim("{$firstName} {$lastName}");
        
        // Ensure contact_person is set (required field)
        if (empty($data['contact_person'])) {
            $data['contact_person'] = $data['name'] ?? '';
        }
        
        // Ensure business_name is set (required field)
        if (empty($data['business_name'])) {
            $data['business_name'] = $data['contact_person'] ?? $data['name'] ?? '';
        }

        \Log::info('Mapped tenant data', [
            'received_data' => $data,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'combined_name' => $data['name'],
            'contact_person' => $data['contact_person'] ?? 'NOT SET',
            'business_name' => $data['business_name'] ?? 'NOT SET'
        ]);

        // Validate the request
        $validator = Validator::make($data, [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:11',
            'address' => 'nullable|string',
            'business_name' => 'required|string|max:255',
            'business_type' => 'nullable|string|max:255',
            'tin' => 'nullable|string|max:50',
            'business_address' => 'nullable|string',
            'contact_person' => 'required|string|max:255',
            'contact_number' => 'nullable|string|max:11',
        ]);

        if ($validator->fails()) {
            \Log::error('Tenant validation failed', [
                'errors' => $validator->errors()->all(),
                'data' => $data,
                'name_field' => $data['name'] ?? 'NOT SET',
                'contact_person_field' => $data['contact_person'] ?? 'NOT SET',
                'business_name_field' => $data['business_name'] ?? 'NOT SET',
                'message' => implode(', ', $validator->errors()->all())
            ]);
            
            // Create a more helpful error message
            $errorDetails = [];
            if ($validator->errors()->has('name')) {
                $errorDetails[] = "Name field (combined from firstName + lastName): " . ($data['name'] ?? 'EMPTY');
            }
            if ($validator->errors()->has('contact_person')) {
                $errorDetails[] = "Contact Person: " . ($data['contact_person'] ?? 'EMPTY');
            }
            if ($validator->errors()->has('business_name')) {
                $errorDetails[] = "Business Name: " . ($data['business_name'] ?? 'EMPTY');
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Validation failed: ' . implode(', ', $validator->errors()->all()),
                'errors' => $validator->errors(),
                'debug' => $errorDetails
            ], 422);
        }

        // Create user account
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => 'tenant',
            'phone' => $data['phone'] ?? $data['contact_number'] ?? null,
            'address' => $data['address'] ?? null,
            'status' => 'active',
        ]);

        // Create tenant profile
        $tenant = Tenant::create([
            'user_id' => $user->id,
            'tenant_code' => 'TEN-' . date('Y') . '-' . str_pad(Tenant::count() + 1, 4, '0', STR_PAD_LEFT),
            'business_name' => $data['business_name'],
            'business_type' => $data['business_type'] ?? null,
            'tin' => $data['tin'] ?? null,
            'business_address' => $data['business_address'] ?? null,
            'contact_person' => $data['contact_person'],
            'contact_number' => $data['contact_number'] ?? $data['phone'] ?? $data['contact_person'] ?? $data['name'] ?? '0000000000',
            'status' => 'active',
        ]);

        // Generate QR Code (with error handling for missing imagick extension)
        try {
            $this->generateQRCode($tenant);
        } catch (\Exception $e) {
            // Log the error but don't fail tenant creation if QR code generation fails
            \Log::warning("QR Code generation failed for tenant {$tenant->id}: " . $e->getMessage());
        }

        try {
            AuditLog::log('create', 'Tenant', $tenant->id, "Created tenant: {$tenant->business_name}", null, $tenant->toArray());
        } catch (\Exception $e) {
            \Log::warning('Failed to log audit', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Tenant created successfully',
            'data' => $tenant->load('user')
        ], 201);
    }

    /**
     * Display the specified tenant
     */
    public function show($id)
    {
        try {
            $tenant = Tenant::with([
                'user',
                'contracts' => function ($query) {
                    $query->with('rentalSpace')->orderBy('created_at', 'desc');
                },
                'payments'
            ])->findOrFail($id);
        } catch (\Exception $e) {
            \Log::error('Failed to load tenant details', [
                'tenant_id' => $id,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found'
            ], 404);
        }

        try {
            AuditLog::log('view', 'Tenant', $tenant->id, "Viewed tenant: {$tenant->business_name}");
        } catch (\Exception $e) {
            \Log::warning('Failed to log audit', ['error' => $e->getMessage()]);
        }

        // Add full URLs for profile picture if it exists
        $tenantArray = $tenant->toArray();
        if ($tenant->profile_picture) {
            try {
                // Generate Cloudinary URL from public_id
                $url = $this->cloudinary->generateUrl($tenant->profile_picture);
                $tenantArray['profile_picture_url'] = $url;
                $tenantArray['profilePicture_url'] = $url;
            } catch (\Exception $e) {
                \Log::warning('Failed to generate Cloudinary URL for tenant detail', [
                    'tenant_id' => $tenant->id,
                    'public_id' => $tenant->profile_picture,
                    'error' => $e->getMessage()
                ]);
                $tenantArray['profile_picture_url'] = null;
                $tenantArray['profilePicture_url'] = null;
            }
        }

        return response()->json([
            'success' => true,
            'data' => $tenantArray
        ]);
    }

    /**
     * Update the specified tenant
     */
    public function update(Request $request, $id)
    {
        $tenant = Tenant::findOrFail($id);

        // Map camelCase input fields to snake_case database field names
        $data = $request->all();
        
        // Extract user-related fields
        $userData = [];
        $firstName = '';
        $lastName = '';
        
        if (isset($data['firstName'])) {
            $firstName = $data['firstName'];
            $userData['first_name'] = $firstName;
            unset($data['firstName']);
        } else {
            $firstName = $tenant->user->first_name ?? '';
        }
        
        if (isset($data['lastName'])) {
            $lastName = $data['lastName'];
            $userData['last_name'] = $lastName;
            unset($data['lastName']);
        } else {
            $lastName = $tenant->user->last_name ?? '';
        }
        
        // Combine firstName and lastName into contact_person for tenant
        $fullName = trim("{$firstName} {$lastName}");
        if (!empty($fullName)) {
            $data['contact_person'] = $fullName;
        }
        
        if (isset($data['email'])) {
            $userData['email'] = $data['email'];
            unset($data['email']);
        }
        
        if (isset($data['address'])) {
            $userData['address'] = $data['address'];
            unset($data['address']);
        }
        
        if (isset($data['contactNumber'])) {
            $userData['phone'] = $data['contactNumber'];
            $data['contact_number'] = $data['contactNumber'];
            unset($data['contactNumber']);
        }
        
        // Map business-related fields
        if (isset($data['businessName'])) {
            $data['business_name'] = $data['businessName'];
            unset($data['businessName']);
        }
        
        if (isset($data['businessType'])) {
            $data['business_type'] = $data['businessType'];
            unset($data['businessType']);
        }
        
        if (isset($data['businessAddress'])) {
            $data['business_address'] = $data['businessAddress'];
            unset($data['businessAddress']);
        }
        
        if (isset($data['contactPerson'])) {
            $data['contact_person'] = $data['contactPerson'];
            unset($data['contactPerson']);
        }

        // Validate tenant data
        $validator = Validator::make($data, [
            'business_name' => 'sometimes|string|max:255',
            'business_type' => 'sometimes|string|max:255',
            'tin' => 'sometimes|string|max:50',
            'business_address' => 'sometimes|string',
            'contact_person' => 'sometimes|string|max:255',
            'contact_number' => 'sometimes|string|max:20',
            'status' => 'sometimes|in:active,inactive,blacklisted',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Update user if user data provided
        if (!empty($userData)) {
            // Validate user data
            $userValidator = Validator::make($userData, [
                'first_name' => 'sometimes|string|max:255',
                'last_name' => 'sometimes|string|max:255',
                'email' => 'sometimes|email|unique:users,email,' . $tenant->user_id,
                'address' => 'sometimes|string',
                'phone' => 'sometimes|string|max:20',
            ]);

            if ($userValidator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'User validation failed',
                    'errors' => $userValidator->errors()
                ], 422);
            }

            $tenant->user->update($userData);
        }

        $oldValues = $tenant->toArray();
        $tenant->update($data);

        try {
            AuditLog::log('update', 'Tenant', $tenant->id, "Updated tenant: {$tenant->business_name}", $oldValues, $tenant->toArray());
        } catch (\Exception $e) {
            \Log::warning('Failed to log audit', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Tenant updated successfully',
            'data' => $tenant->load('user')
        ]);
    }

    /**
     * Remove the specified tenant
     */
    public function destroy($id)
    {
        $tenant = Tenant::findOrFail($id);
        $tenantName = $tenant->business_name;
        
        try {
            AuditLog::log('delete', 'Tenant', $tenant->id, "Deleted tenant: {$tenantName}");
        } catch (\Exception $e) {
            \Log::warning('Failed to log audit', ['error' => $e->getMessage()]);
        }
        
        $tenant->user->delete(); // This will cascade delete the tenant

        return response()->json([
            'success' => true,
            'message' => 'Tenant deleted successfully'
        ]);
    }

    /**
     * Generate QR code for tenant
     */
    public function generateQRCode($tenant)
    {
        // Check if QrCode class is available
        if (!class_exists('SimpleSoftwareIO\QrCode\Facades\QrCode')) {
            \Log::warning("QrCode package not installed, skipping QR code generation for tenant {$tenant->id}");
            return null;
        }

        try {
            $qrData = json_encode([
                'tenant_code' => $tenant->tenant_code,
                'business_name' => $tenant->business_name,
                'contact_person' => $tenant->contact_person,
                'contact_number' => $tenant->contact_number,
            ]);

            $qrCode = \SimpleSoftwareIO\QrCode\Facades\QrCode::format('png')
                ->size(300)
                ->generate($qrData);

            $filename = 'qr-' . $tenant->tenant_code . '.png';
            $path = 'qrcodes/' . $filename;

            Storage::disk('public')->put($path, $qrCode);

            $tenant->qr_code = $path;
            $tenant->save();

            return $path;
        } catch (\Exception $e) {
            \Log::warning("QR code generation failed for tenant {$tenant->id}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get tenant QR code with contract details
     */
    public function getQRCodeWithDetails($id)
    {
        $tenant = Tenant::with(['activeContracts.rentalSpace'])->findOrFail($id);

        $qrData = [
            'tenant_code' => $tenant->tenant_code,
            'business_name' => $tenant->business_name,
            'contact_person' => $tenant->contact_person,
            'contact_number' => $tenant->contact_number,
            'contracts' => $tenant->activeContracts->map(function ($contract) {
                return [
                    'contract_number' => $contract->contract_number,
                    'rental_space' => [
                        'name' => $contract->rentalSpace->name,
                        'type' => $contract->rentalSpace->getSpaceTypeLabel(),
                        'size_sqm' => $contract->rentalSpace->size_sqm,
                        'map_image' => $contract->rentalSpace->map_image,
                    ],
                    'start_date' => $contract->start_date->format('Y-m-d'),
                    'end_date' => $contract->end_date->format('Y-m-d'),
                    'monthly_rental' => $contract->monthly_rental,
                ];
            }),
        ];

        return response()->json([
            'success' => true,
            'data' => $qrData,
            'qr_image' => $tenant->qr_code ? Storage::url($tenant->qr_code) : null,
        ]);
    }

    /**
     * Scan QR code and get tenant details
     */
    public function scanQRCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tenant_code' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $tenant = Tenant::with(['activeContracts.rentalSpace'])
            ->where('tenant_code', $request->tenant_code)
            ->first();

        if (!$tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found'
            ], 404);
        }

        return $this->getQRCodeWithDetails($tenant->id);
    }

    /**
     * Upload tenant profile picture
     */
    public function uploadPicture(Request $request, $id)
    {
        \Log::info('Upload picture request received', [
            'tenant_id' => $id,
            'has_file' => $request->hasFile('profile_picture'),
            'headers' => $request->headers->all()
        ]);

        $tenant = Tenant::findOrFail($id);

        // Validate the uploaded file
        $validator = Validator::make($request->all(), [
            'profile_picture' => 'required|image|max:51200', // 50MB max, accepts all image types
        ]);

        if ($validator->fails()) {
            \Log::error('Validation failed for upload', ['errors' => $validator->errors()]);
            return response()->json([
                'success' => false,
                'message' => 'Invalid image file',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Delete old picture from Cloudinary if exists
            if ($tenant->profile_picture) {
                \Log::info('Attempting to delete old picture from Cloudinary', [
                    'tenant_id' => $id,
                    'public_id' => $tenant->profile_picture
                ]);
                
                $deleted = $this->cloudinary->deleteFile($tenant->profile_picture);
                
                \Log::info('Old picture deletion result', [
                    'tenant_id' => $id,
                    'deleted' => $deleted,
                    'public_id' => $tenant->profile_picture
                ]);
            }

            // Upload new picture to Cloudinary
            $file = $request->file('profile_picture');
            $public_id = 'tenant-' . $tenant->id . '-' . time();
            
            $uploadResult = $this->cloudinary->uploadFile($file, 'profile-pictures', $public_id);
            
            if (!$uploadResult['success']) {
                throw new \Exception('Cloudinary upload failed: ' . $uploadResult['message']);
            }

            // Update tenant record with Cloudinary public_id
            $tenant->profile_picture = $uploadResult['public_id'];
            $tenant->save();
            
            \Log::info('Profile picture uploaded to Cloudinary successfully', [
                'tenant_id' => $id,
                'public_id' => $uploadResult['public_id'],
                'url' => $uploadResult['url']
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Profile picture uploaded successfully',
                'data' => [
                    'profilePicture' => $uploadResult['public_id'],
                    'profile_picture' => $uploadResult['public_id'],
                    'url' => $uploadResult['url']
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Profile picture upload failed', [
                'tenant_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to upload picture: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Serve file from storage with proper headers
     */
    public function serveFile($path)
    {
        // Prevent directory traversal attacks
        if (strpos($path, '..') !== false || strpos($path, './') === 0) {
            return response()->json(['error' => 'Invalid path'], 403);
        }

        $fullPath = storage_path('app/public/' . $path);
        
        if (!file_exists($fullPath)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        // Get MIME type
        $mimeType = mime_content_type($fullPath);
        
        // Send file with proper headers
        return response()->file($fullPath, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=31536000',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type'
        ]);
    }
}
