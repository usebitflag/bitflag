<?php

declare(strict_types=1);

namespace App\Modules\Environments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateEnvironmentSettingsRequest extends FormRequest
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
            'allowed_origins' => ['present', 'array', 'max:50'],
            'allowed_origins.*' => ['string', 'max:300'],
            'max_snapshot_age' => ['nullable', 'integer'],
            'protected' => ['required', 'boolean'],
            'required_approvals' => ['required', 'integer', 'min:0', 'max:5'],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }
}
