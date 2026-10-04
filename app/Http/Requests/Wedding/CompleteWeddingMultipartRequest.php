<?php

namespace App\Http\Requests\Wedding;

use Illuminate\Foundation\Http\FormRequest;

class CompleteWeddingMultipartRequest extends FormRequest
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
        $maxParts = (int) config('wedding.multipart.max_parts');

        return [
            'upload_id' => ['required', 'string', 'max:1024'],
            'parts' => ['required', 'array', 'min:1', 'max:'.$maxParts],
            'parts.*.part_number' => ['required', 'integer', 'min:1', 'max:'.$maxParts, 'distinct'],
            'parts.*.etag' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return list<array{part_number: int, etag: string}>
     */
    public function parts(): array
    {
        return collect($this->validated('parts'))
            ->map(fn (array $part): array => [
                'part_number' => (int) $part['part_number'],
                'etag' => (string) $part['etag'],
            ])
            ->sortBy('part_number')
            ->values()
            ->all();
    }
}
