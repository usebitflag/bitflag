<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Domain\PasswordRules;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Checks the shape of a sign-up. Nothing here depends on whether the address has an account, so an error never reveals it.
 */
final class SignUpRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                $email = $this->input('email');

                foreach (app(PasswordRules::class)->violations(is_string($value) ? $value : '', is_string($email) ? $email : null) as $message) {
                    $fail($message);
                }
            }],
        ];
    }
}
