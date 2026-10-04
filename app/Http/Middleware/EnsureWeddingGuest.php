<?php

namespace App\Http\Middleware;

use App\Support\WeddingGuest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for every wedding-hub route past the email prompt: the visitor must
 * have entered an email this session. The resolved guest is shared with the
 * controller as the `weddingGuest` request attribute.
 */
class EnsureWeddingGuest
{
    public function handle(Request $request, Closure $next): Response
    {
        $guest = WeddingGuest::fromSession($request->session());

        if ($guest === null) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Enter your email to continue.'], 401);
            }

            return redirect()->route('wedding.show');
        }

        $request->attributes->set('weddingGuest', $guest);

        return $next($request);
    }
}
