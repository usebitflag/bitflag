<?php

declare(strict_types=1);

namespace App\Modules\Environments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateEnvironmentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:40'],
            'key' => ['required', 'string', 'max:40'],
            'color' => ['required', 'string', 'max:32'],
            'after' => ['nullable', 'string', 'max:40'],
            'protected' => ['required', 'boolean'],
        ];
    }
}
