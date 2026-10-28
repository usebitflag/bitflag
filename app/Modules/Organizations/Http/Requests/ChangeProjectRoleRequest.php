<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Requests;

use App\Shared\Capabilities\ProjectRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangeProjectRoleRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(ProjectRole::class)],
            'expected_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
