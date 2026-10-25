<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Requests;

use App\Shared\Capabilities\OrgRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ChangeOrganizationRoleRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return ['role' => ['required', Rule::enum(OrgRole::class)]];
    }
}
