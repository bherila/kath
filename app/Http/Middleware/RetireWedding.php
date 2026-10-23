<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class RetireWedding
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = rtrim((string) config('wedding.external_url'), '/');
        if ($origin === '') {
            if (config('wedding.migration_frozen')) {
                return response('The wedding gallery is moving. Please try again shortly.', 503)
                    ->header('Cache-Control', 'no-store')->header('Retry-After', '60');
            }

            return $next($request);
        }

        $parts = parse_url($origin);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! empty($parts['path'])) {
            throw new InvalidArgumentException('WEDDING_EXTERNAL_URL must be an HTTPS origin.');
        }

        $destination = $origin.'/events';
        if (($request->isMethod('GET') || $request->isMethod('HEAD'))
            && ! $request->is('wedding/api/*') && ! $request->expectsJson()) {
            return redirect()->away($destination)->withHeaders(['Cache-Control' => 'no-store']);
        }

        return response()->json([
            'message' => 'The wedding gallery has moved. Please continue on the new site.',
            'url' => $destination,
        ], 410)->header('Cache-Control', 'no-store');
    }
}
