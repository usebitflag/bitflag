<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Requests;

use App\Shared\Capabilities\OrgRole;
use App\Shared\Capabilities\ProjectRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class InviteMembersRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'emails' => ['required', 'string', 'max:5000'],
            'org_role' => ['required', Rule::enum(OrgRole::class)],
            'projects' => ['present', 'array', 'max:50'],
            'projects.*.id' => ['required', 'string', 'max:40'],
            'projects.*.role' => ['required', Rule::enum(ProjectRole::class)],
        ];
    }
}
