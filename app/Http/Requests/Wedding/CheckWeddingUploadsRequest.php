<?php

namespace App\Http\Requests\Wedding;

use Illuminate\Foundation\Http\FormRequest;

class CheckWeddingUploadsRequest extends FormRequest
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
        return [
            'hashes' => ['present', 'array', 'max:500'],
            'hashes.*' => ['string', 'regex:/^[0-9a-f]{64}$/'],
        ];
    }

    /**
     * @return list<string>
     */
    public function hashes(): array
    {
        return array_values(array_unique(array_map('strval', $this->validated('hashes'))));
    }
}
