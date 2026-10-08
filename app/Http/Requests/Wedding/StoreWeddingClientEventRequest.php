<?php

namespace App\Http\Requests\Wedding;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A diagnostic event from a guest's browser. Only file metadata (type, size,
 * extension) and error text are accepted, never file names or contents.
 */
class StoreWeddingClientEventRequest extends FormRequest
{
    public const EVENTS = [
        // The picker closed with files / with none (cancelled, or the OS
        // handed nothing over, e.g. an iCloud photo that didn't download).
        'picker_change',
        'picker_empty',
        // A file the page refused, an upload that failed, a crash in the
        // uploader itself.
        'file_rejected',
        'upload_failed',
        'uploader_error',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'event' => ['required', 'string', Rule::in(self::EVENTS)],
            'reason' => ['nullable', 'string', 'max:40'],
            'message' => ['nullable', 'string', 'max:300'],
            'files' => ['nullable', 'array', 'max:20'],
            'files.*.type' => ['nullable', 'string', 'max:100'],
            'files.*.ext' => ['nullable', 'string', 'max:10'],
            'files.*.size' => ['nullable', 'integer', 'min:0'],
            'count' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
