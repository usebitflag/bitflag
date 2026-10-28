<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CreateOrganizationRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:40'],
            'team_size' => ['nullable', 'string', 'in:solo,small,medium,large'],
        ];
    }
}
