<?php

namespace App\Http\Requests\Wedding;

use Illuminate\Foundation\Http\FormRequest;

class PresignWeddingUploadPartsRequest extends FormRequest
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
            'part_numbers' => ['required', 'array', 'min:1', 'max:1000'],
            'part_numbers.*' => ['required', 'integer', 'min:1', 'max:'.$maxParts, 'distinct'],
            'part_sizes' => ['required', 'array', 'min:1', 'max:1000'],
            'part_sizes.*' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return list<int>
     */
    public function partNumbers(): array
    {
        return array_values(array_map('intval', $this->validated('part_numbers')));
    }

    /**
     * @return array<int, int>
     */
    public function partSizes(): array
    {
        $sizes = [];
        foreach ($this->validated('part_sizes') as $partNumber => $sizeBytes) {
            $sizes[(int) $partNumber] = (int) $sizeBytes;
        }

        return $sizes;
    }
}
