<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Domain\FlagKeyFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateProjectSettingsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'key' => ['required', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:280'],
            'time_zone' => ['required', 'string', 'max:64'],
            'flag_key_format' => ['required', Rule::enum(FlagKeyFormat::class)],
            'stale_after_days' => ['required', 'integer'],
            'require_flag_description' => ['required', 'boolean'],
            'require_flag_owner' => ['required', 'boolean'],
            'client_visible_by_default' => ['required', 'boolean'],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }
}
