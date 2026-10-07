<?php

namespace App\Http\Requests\Wedding;

use App\Models\WeddingUpload;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreWeddingUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $mimeTypes = array_merge(
            (array) config('wedding.mime_types.photo'),
            (array) config('wedding.mime_types.video'),
        );

        return [
            'filename' => ['required', 'string', 'max:255'],
            'content_type' => ['required', 'string', Rule::in($mimeTypes)],
            'size' => ['required', 'integer', 'min:1'],
            'file_hash' => ['nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
            // Photos: base64 32-byte blockhash of each of the eight
            // rotation/mirror orientations (index 0 = as displayed), and the
            // decoded pixel size, used to show the best of near-identical copies.
            'perceptual_hashes' => ['nullable', 'array', 'size:8'],
            'perceptual_hashes.*' => ['required', 'string', 'regex:/^[A-Za-z0-9+\/]{43}=$/'],
            // When it was taken, from the file's EXIF/QuickTime metadata.
            'captured_at' => ['nullable', 'date'],
            'width' => ['nullable', 'required_with:height', 'integer', 'min:1', 'max:100000'],
            'height' => ['nullable', 'required_with:width', 'integer', 'min:1', 'max:100000'],
            // Exact byte sizes of the client-made JPEG derivatives; each is
            // signed into its presigned PUT, so storage rejects any other size.
            'display_size' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('wedding.max_bytes.display')],
            'thumbnail_size' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('wedding.max_bytes.thumbnail')],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $max = (int) config('wedding.max_bytes.'.$this->kind());
                if ((int) $this->input('size') > $max) {
                    $validator->errors()->add('size', 'This file is too large to share here.');
                }
            },
        ];
    }

    public function kind(): string
    {
        return in_array($this->input('content_type'), (array) config('wedding.mime_types.video'), true)
            ? WeddingUpload::KIND_VIDEO
            : WeddingUpload::KIND_PHOTO;
    }

    /**
     * @return list<string>|null
     */
    public function perceptualHashes(): ?array
    {
        $hashes = $this->validated('perceptual_hashes');

        return is_array($hashes) ? array_values(array_map('strval', $hashes)) : null;
    }

    /**
     * @return array{width: int, height: int}|null
     */
    public function dimensions(): ?array
    {
        $width = $this->validated('width');
        $height = $this->validated('height');

        return $width !== null && $height !== null ? ['width' => (int) $width, 'height' => (int) $height] : null;
    }

    /**
     * The reported capture time, or null when missing or implausible (a
     * camera clock that was never set, or one in the future).
     */
    public function capturedAt(): ?CarbonImmutable
    {
        $value = $this->validated('captured_at');
        if (! is_string($value)) {
            return null;
        }

        $capturedAt = CarbonImmutable::parse($value)->utc();

        return $capturedAt->year >= 2000 && $capturedAt->lte(now()->addDay()) ? $capturedAt : null;
    }
}
