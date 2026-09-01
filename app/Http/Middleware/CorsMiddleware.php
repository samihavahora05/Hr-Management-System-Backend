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
        $origin = $request->header('Origin') ?: '*';

        $headers = [
            'Access-Control-Allow-Origin'      => $origin,
            'Access-Control-Allow-Methods'     => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers'     => 'Content-Type, Authorization, X-Requested-With, Accept, Origin, Application, X-CSRF-TOKEN, X-XSRF-TOKEN',
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Max-Age'           => '86400',
        ];

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
