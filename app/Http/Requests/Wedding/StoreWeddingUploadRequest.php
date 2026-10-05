<?php

namespace App\Http\Requests\Wedding;

use App\Models\WeddingUpload;
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
            // Base64 of the 32-byte blockhash.
            'perceptual_hash' => ['nullable', 'string', 'regex:/^[A-Za-z0-9+\/]{43}=$/'],
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
}
