<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CorsMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get allowed origins
        $allowedOrigins = [
            'https://contract-monitoring-frontend.vercel.app',
            'https://contract-monitoring-frontend-b8t2.vercel.app',
            'https://contractmonitoringbackend-production.up.railway.app',
            'http://localhost:3000',
            'http://127.0.0.1:3000',
            'http://localhost',
            'http://127.0.0.1',
        ];

        $origin = $request->header('origin');

        // Allow any private LAN origin (192.168.x.x, 10.x.x.x, 172.16-31.x.x) on any port
        $isLanOrigin = false;
        if ($origin) {
            $host = parse_url($origin, PHP_URL_HOST) ?? '';
            $isLanOrigin = preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $host) === 1;
        }

        $isAllowed = $origin && (in_array($origin, $allowedOrigins) || $isLanOrigin);

        // Always allow requests from allowed origins
        if ($isAllowed) {
            // Handle OPTIONS preflight requests
            if ($request->getMethod() === 'OPTIONS') {
                return response('', 200)
                    ->header('Access-Control-Allow-Origin', $origin)
                    ->header('Access-Control-Allow-Methods', 'GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS')
                    ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, X-CSRF-TOKEN')
                    ->header('Access-Control-Allow-Credentials', 'true')
                    ->header('Access-Control-Max-Age', '86400')
                    ->header('Vary', 'Origin');
            }
            
            // Add CORS headers to all responses
            $response = $next($request);
            
            return $response
                ->header('Access-Control-Allow-Origin', $origin)
                ->header('Access-Control-Allow-Methods', 'GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, X-CSRF-TOKEN')
                ->header('Access-Control-Allow-Credentials', 'true')
                ->header('Access-Control-Max-Age', '86400')
                ->header('Vary', 'Origin');
        }
        
        // For disallowed origins, still process the request but don't add CORS headers
        return $next($request);
    }
}
