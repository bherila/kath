<?php

namespace App\Http\Controllers\Wedding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wedding\AbortWeddingMultipartRequest;
use App\Http\Requests\Wedding\CheckWeddingUploadsRequest;
use App\Http\Requests\Wedding\CompleteWeddingMultipartRequest;
use App\Http\Requests\Wedding\PresignWeddingUploadPartsRequest;
use App\Http\Requests\Wedding\StoreWeddingUploadRequest;
use App\Models\WeddingUpload;
use App\Services\Wedding\UploadQuotaExceeded;
use App\Services\Wedding\WeddingUploadService;
use App\Support\WeddingGuest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Guest photo/video contributions, uploaded straight from the browser to R2
 * via presigned URLs. Every mutation of an existing upload requires the
 * session token that created it.
 */
class WeddingUploadController extends Controller
{
    public function __construct(private readonly WeddingUploadService $uploads) {}

    /**
     * Pre-flight for a multi-select: which of these files are already shared?
     */
    public function check(CheckWeddingUploadsRequest $request): JsonResponse
    {
        return response()->json(['existing' => $this->uploads->existingHashes($request->hashes())]);
    }

    public function store(StoreWeddingUploadRequest $request): JsonResponse
    {
        $guest = $this->guest($request);
        $fileHash = $request->validated('file_hash');

        if ($fileHash !== null && $this->uploads->findExactDuplicate($fileHash, $guest) !== null) {
            return response()->json([
                'message' => 'This one has already been shared.',
                'duplicate' => true,
            ], 409);
        }

        try {
            $result = $this->uploads->createPendingUpload(
                $guest,
                $request->kind(),
                (string) $request->validated('filename'),
                (string) $request->validated('content_type'),
                (int) $request->validated('size'),
                $fileHash,
                $request->perceptualHashes(),
                $request->dimensions(),
                $request->validated('display_size') !== null ? (int) $request->validated('display_size') : null,
                $request->validated('thumbnail_size') !== null ? (int) $request->validated('thumbnail_size') : null,
                (string) $request->ip(),
            );
        } catch (UploadQuotaExceeded $e) {
            return response()->json(['message' => $e->getMessage(), 'quota_exceeded' => true], 429);
        }

        $upload = $result['upload'];

        return response()->json([
            'ulid' => $upload->ulid,
            'multipart' => $this->uploads->usesMultipart($upload),
            'upload_url' => $result['upload_url'],
            'upload_headers' => $result['upload_headers'],
            'display_upload' => $result['display_upload'],
            'thumbnail_upload' => $result['thumbnail_upload'],
        ], 201);
    }

    public function complete(Request $request, WeddingUpload $upload): JsonResponse
    {
        $this->authorizeOwner($request, $upload);

        if (! $this->uploads->completeUpload($upload)) {
            return $this->unverified();
        }

        return response()->json(['ulid' => $upload->ulid, 'status' => $upload->status]);
    }

    public function initMultipart(Request $request, WeddingUpload $upload): JsonResponse
    {
        $this->authorizeOwner($request, $upload);

        $session = $this->uploads->initMultipartUpload($upload);
        if ($session === null) {
            return response()->json(['message' => 'This upload cannot start a multipart session.'], 422);
        }

        return response()->json($session);
    }

    public function presignParts(PresignWeddingUploadPartsRequest $request, WeddingUpload $upload): JsonResponse
    {
        $this->authorizeOwner($request, $upload);

        $parts = $this->uploads->signedMultipartPartUrls(
            $upload,
            (string) $request->validated('upload_id'),
            $request->partNumbers(),
            $request->partSizes(),
        );

        if ($parts === null) {
            return response()->json(['message' => 'Multipart upload session was not found.'], 404);
        }

        return response()->json(['parts' => $parts]);
    }

    public function completeMultipart(CompleteWeddingMultipartRequest $request, WeddingUpload $upload): JsonResponse
    {
        $this->authorizeOwner($request, $upload);

        if (! $this->uploads->completeMultipartUpload($upload, (string) $request->validated('upload_id'), $request->parts())) {
            return $this->unverified();
        }

        return response()->json(['ulid' => $upload->ulid, 'status' => $upload->status]);
    }

    public function abortMultipart(AbortWeddingMultipartRequest $request, WeddingUpload $upload): JsonResponse
    {
        $this->authorizeOwner($request, $upload);

        if (! $this->uploads->abortMultipartUpload($upload, (string) $request->validated('upload_id'))) {
            return response()->json(['message' => 'Multipart upload session was not found.'], 404);
        }

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, WeddingUpload $upload): JsonResponse
    {
        $this->authorizeOwner($request, $upload);

        $this->uploads->hide($upload);

        return response()->json(['ok' => true]);
    }

    private function guest(Request $request): WeddingGuest
    {
        /** @var WeddingGuest $guest */
        $guest = $request->attributes->get('weddingGuest');

        return $guest;
    }

    /**
     * Someone else's upload is indistinguishable from a missing one.
     */
    private function authorizeOwner(Request $request, WeddingUpload $upload): void
    {
        if ($upload->status === WeddingUpload::STATUS_HIDDEN || ! $upload->isOwnedBy($this->guest($request)->tokenHash())) {
            abort(404);
        }
    }

    private function unverified(): JsonResponse
    {
        return response()->json([
            'message' => 'The upload could not be verified, or this file was just shared by someone else.',
        ], 422);
    }
}
