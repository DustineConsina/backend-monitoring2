<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictTenantAccess
{
    private const TENANT_ALLOWED_PATHS = [
        'api/logout',
        'api/me',
        'api/profile',
        'api/tenant/portal',
        'api/tenant/complaints',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && strtolower((string) $user->role) === 'tenant') {
            $path = $request->path();
            $allowed = in_array($path, self::TENANT_ALLOWED_PATHS, true)
                || preg_match('~^api/tenant/complaints/\d+/replies$~', $path) === 1
                || preg_match('~^api/contracts/\d+/(view|lease)$~', $path) === 1;
            if (!$allowed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Tenant accounts can only access their own portal and complaints.',
                ], 403);
            }
        }

        return $next($request);
    }
}
