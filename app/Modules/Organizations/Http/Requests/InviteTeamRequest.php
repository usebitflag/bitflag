<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Requests;

use App\Shared\Capabilities\ProjectRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class InviteTeamRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'emails' => ['required', 'string', 'max:5000'],
            'role' => ['required', Rule::enum(ProjectRole::class)],
        ];
    }
}
