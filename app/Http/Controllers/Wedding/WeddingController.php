<?php

namespace App\Http\Controllers\Wedding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wedding\EnterWeddingRequest;
use App\Services\Wedding\HlsService;
use App\Support\WeddingGuest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Katherine & Jack Wedding Hub: an email prompt, then the hub page (ceremony
 * video, photo/video sharing, shared gallery) rendered as a React island.
 */
class WeddingController extends Controller
{
    public function __construct(private readonly HlsService $hls) {}

    public function show(Request $request): View
    {
        $guest = WeddingGuest::fromSession($request->session());

        if ($guest === null) {
            return view('wedding.gate');
        }

        return view('wedding.hub', [
            'bootstrap' => [
                'guest' => ['name' => $guest->name, 'email' => $guest->email],
                'ceremony' => [
                    'master_url' => $this->hls->resolveCeremony() !== null
                        ? route('wedding.hls', ['source' => 'ceremony', 'path' => 'master.m3u8'], false)
                        : null,
                ],
                'limits' => [
                    'photo_bytes' => (int) config('wedding.max_bytes.photo'),
                    'video_bytes' => (int) config('wedding.max_bytes.video'),
                    'photo_types' => config('wedding.mime_types.photo'),
                    'video_types' => config('wedding.mime_types.video'),
                ],
            ],
        ]);
    }

    public function enter(EnterWeddingRequest $request): RedirectResponse
    {
        WeddingGuest::enter(
            $request->session(),
            (string) $request->validated('email'),
            $request->validated('name'),
        );

        return redirect()->route('wedding.show');
    }

    public function leave(Request $request): RedirectResponse
    {
        WeddingGuest::leave($request->session());

        return redirect()->route('wedding.show');
    }
}
