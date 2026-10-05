<?php

namespace App\Http\Requests\Wedding;

use Illuminate\Foundation\Http\FormRequest;

class EnterWeddingRequest extends FormRequest
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
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'name' => ['nullable', 'string', 'max:60'],
        ];
    }
}
