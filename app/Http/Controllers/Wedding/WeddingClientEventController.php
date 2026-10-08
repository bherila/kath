<?php

namespace App\Http\Controllers\Wedding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wedding\StoreWeddingClientEventRequest;
use App\Support\WeddingGuest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Records what a guest's browser saw go wrong while uploading, since
 * client-side failures (a photo picker that returns nothing, a refused file)
 * otherwise leave no trace on the server.
 */
class WeddingClientEventController extends Controller
{
    public function store(StoreWeddingClientEventRequest $request): Response
    {
        /** @var WeddingGuest $guest */
        $guest = $request->attributes->get('weddingGuest');

        Log::channel('wedding_client')->info((string) $request->validated('event'), [
            // A short token prefix ties one guest's events together without
            // logging who they are.
            'guest' => substr($guest->tokenHash(), 0, 8),
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 300),
            'detail' => collect($request->validated())->except('event')->all(),
        ]);

        return response()->noContent();
    }
}
