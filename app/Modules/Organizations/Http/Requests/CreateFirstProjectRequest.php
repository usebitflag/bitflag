<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateFirstProjectRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'key' => ['required', 'string', 'max:40'],
            'environments' => ['required', 'array', 'min:1', 'max:10'],
            'environments.*.name' => ['required', 'string', 'max:40'],
            'environments.*.key' => ['required', 'string', 'max:40'],
        ];
    }
}
