<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CorsMiddleware
{
    /**
     * Handle an incoming request and attach CORS headers.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $trustedOrigins = [
            'https://hrms.blueboxx.in',
            'http://hrms.blueboxx.in',
            'https://hrms-backend.blueboxx.in',
            'http://hrms-backend.blueboxx.in',
            'https://hrms_backend.blueboxx.in',
            'http://hrms_backend.blueboxx.in',
            'http://localhost:3000',
            'http://localhost:8000',
            'http://127.0.0.1:3000',
            'http://127.0.0.1:8000',
        ];

        $appUrl = config('app.url');
        if ($appUrl) {
            $trustedOrigins[] = rtrim($appUrl, '/');
        }

        $incomingOrigin = $request->header('Origin');
        $isTrusted = false;
        $allowOrigin = 'https://hrms.blueboxx.in';

        if ($incomingOrigin) {
            $normalized = rtrim($incomingOrigin, '/');
            if (in_array($normalized, $trustedOrigins, true) || preg_match('/^https?:\/\/([a-zA-Z0-9-]+\.)?blueboxx\.in(:\d+)?$/', $normalized)) {
                $isTrusted = true;
                $allowOrigin = $incomingOrigin;
            }
        } elseif (!$request->isMethod('OPTIONS')) {
            $isTrusted = true;
            $allowOrigin = '*';
        }

        $headers = [
            'Access-Control-Allow-Origin'  => $allowOrigin,
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, Accept, Origin, Application, X-CSRF-TOKEN, X-XSRF-TOKEN, X-Auth-Token, X-Bearer-Token',
            'Access-Control-Max-Age'       => '86400',
        ];

        if ($isTrusted && $allowOrigin !== '*') {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        // Directly handle preflight OPTIONS requests with 200 OK
        if ($request->isMethod('OPTIONS')) {
            return response('', 200, $headers);
        }

        $response = $next($request);

        // Attach CORS headers to response
        if (method_exists($response, 'header')) {
            foreach ($headers as $key => $value) {
                $response->header($key, $value);
            }
        } elseif (isset($response->headers) && method_exists($response->headers, 'set')) {
            foreach ($headers as $key => $value) {
                $response->headers->set($key, $value);
            }
        }

        return $response;
    }
}
