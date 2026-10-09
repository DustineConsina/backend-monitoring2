<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Tenant;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    /**
     * Register a new user
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|in:admin,staff,tenant',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'phone' => $request->phone,
            'address' => $request->address,
            'status' => 'active',
        ]);

        // If role is tenant, create tenant profile
        if ($request->role === 'tenant') {
            Tenant::create([
                'user_id' => $user->id,
                'tenant_code' => 'TEN-' . date('Y') . '-' . str_pad($user->id, 4, '0', STR_PAD_LEFT),
                'business_name' => $request->business_name ?? $request->name,
                'contact_person' => $request->name,
                'contact_number' => $request->phone,
                'status' => 'active',
            ]);
        }

        AuditLog::log('create', 'User', $user->id, "User {$user->name} registered");

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'User registered successfully',
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    /**
     * Login user
     */
    public function login(Request $request)
    {
        try {
            \Illuminate\Support\Facades\Log::info('Login attempt', ['email' => $request->email]);
            
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'password' => 'required',
            ]);

            if ($validator->fails()) {
                \Illuminate\Support\Facades\Log::warning('Login validation failed', ['errors' => $validator->errors()]);
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            \Illuminate\Support\Facades\Log::info('Attempting authentication', ['email' => $request->email]);

            $user = User::with('tenant')->where('email', $request->email)->first();
            $passwordMatches = $user && Hash::check((string) $request->password, (string) $user->password);

            // Older tenant records may have a valid QR-access hash while their
            // separate portal login password was never initialized correctly.
            // Accept that existing hash as a login credential so tenants do not
            // have to reset a QR password that already appears in tenant details.
            if (!$passwordMatches && $user && strtolower((string) $user->role) === 'tenant') {
                $passwordMatches = $user->tenant?->qr_password_hash
                    && Hash::check((string) $request->password, $user->tenant->qr_password_hash);
            }

            if (!$user || !$passwordMatches) {
                \Illuminate\Support\Facades\Log::warning('Invalid credentials', ['email' => $request->email]);
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid credentials'
                ], 401);
            }

            \Illuminate\Support\Facades\Log::info('User found', ['user_id' => $user->id, 'status' => $user->status]);

            if ($user->status !== 'active') {
                \Illuminate\Support\Facades\Log::warning('Inactive account', ['user_id' => $user->id, 'status' => $user->status]);
                return response()->json([
                    'success' => false,
                    'message' => 'Your account is inactive. Please contact the administrator.'
                ], 403);
            }

            if (strtolower((string) $user->role) === 'tenant' && (!$user->tenant || strtolower((string) $user->tenant->status) !== 'active')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant account not found or inactive. Please contact the administrator.',
                ], 403);
            }

            \Illuminate\Support\Facades\Log::info('Creating token for user', ['user_id' => $user->id]);
            // Create token BEFORE audit logging
            $token = $user->createToken('auth_token')->plainTextToken;
            \Illuminate\Support\Facades\Log::info('Token created successfully', ['user_id' => $user->id]);

            // Log audit with explicit user ID (not relying on auth())
            try {
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'login',
                    'model_type' => 'User',
                    'model_id' => $user->id,
                    'description' => "User {$user->name} logged in",
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
                \Illuminate\Support\Facades\Log::info('Audit log created', ['user_id' => $user->id]);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning('Audit log failed during login', ['error' => $e->getMessage(), 'user_id' => $user->id]);
            }

            // Return simplified user object to avoid serialization issues
            $responseData = [
                'success' => true,
                'message' => 'Login successful',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'status' => $user->status,
                ],
                'token' => $token,
            ];
            
            \Illuminate\Support\Facades\Log::info('Returning login response', ['user_id' => $user->id]);
            return response()->json($responseData);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Login error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Login failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Logout user
     */
    public function logout(Request $request)
    {
        $user = $request->user();
        AuditLog::log('logout', 'User', $user->id, "User {$user->name} logged out");
        
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Get authenticated user
     */
    public function me(Request $request)
    {
        return response()->json([
            'success' => true,
            'user' => $request->user()->load('tenant')
        ]);
    }

    /**
     * Update profile
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'address' => 'sometimes|string',
            'current_password' => 'required_with:new_password',
            'new_password' => 'sometimes|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $oldValues = $user->toArray();

        if ($request->filled('name')) {
            $user->name = $request->name;
        }

        if ($request->filled('phone')) {
            $user->phone = $request->phone;
        }

        if ($request->filled('address')) {
            $user->address = $request->address;
        }

        if ($request->filled('new_password')) {
            if (!Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Current password is incorrect'
                ], 422);
            }
            $user->password = Hash::make($request->new_password);
        }

        $user->save();

        AuditLog::log('update', 'User', $user->id, "User {$user->name} updated profile", $oldValues, $user->toArray());

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'user' => $user
        ]);
    }
}
