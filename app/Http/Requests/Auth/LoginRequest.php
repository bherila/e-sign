<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Credentials submitted to the standalone password form.
 *
 * Shape only. Whether the account exists, whether the password matches, and whether the
 * account may sign in are decided in the controller, and all three produce the same message
 * so the form cannot be used to enumerate accounts.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            // Bounded so an unauthenticated request cannot ask the password hasher to chew
            // through an arbitrarily long string.
            'password' => ['required', 'string', 'max:1024'],
        ];
    }

    public function credentialEmail(): string
    {
        return (string) $this->validated('email');
    }

    public function credentialPassword(): string
    {
        return (string) $this->validated('password');
    }
}
