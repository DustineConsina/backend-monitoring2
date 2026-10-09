<?php
Route::get('/test-db', function() {
    try {
        $admin = \App\Models\User::where('email', 'Joannaruby@pfda.gov.ph')->first();
        
        if ($admin) {
            return response()->json([
                'success' => true,
                'admin_found' => true,
                'user' => [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'role' => $admin->role,
                    'status' => $admin->status,
                ]
            ]);
        } else {
            return response()->json([
                'success' => false,
                'admin_found' => false,
                'message' => 'Admin user not found'
            ], 404);
        }
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage()
        ], 500);
    }
});
