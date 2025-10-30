<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
            'agent_code' => ['nullable', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate()
    {
        $this->ensureIsNotRateLimited();

        // Check if the input contains agent_code for authentication
        $credentials = $this->only(['agent_code', 'password','email']);
        $remember = $this->boolean('remember');

        // Determine if we are authenticating an agent
        $isAgentLogin = isset($credentials['agent_code']) && !empty($credentials['agent_code']);

        if ($isAgentLogin) {
            // Authenticate using agent_code for agents
            $user = \App\Models\User::where('agent_code', $credentials['agent_code'])->where('email',$credentials['email'])->first();

            if (!$user || !in_array($user->type, ['agent', 'staff'])) {
                throw ValidationException::withMessages([
                    'agent_code' => 'Invalid agent code or the account is not an agent.',
                ]);
            }


            // Attempt login with agent_code and password
            if (!Auth::attempt(['agent_code' => $credentials['agent_code'], 'email' => $credentials['email'], 'password' => $credentials['password']], $remember)) {
                RateLimiter::hit($this->throttleKey());

                throw ValidationException::withMessages([
                    'agent_code' => trans('auth.failed'),
                ]);
            }
            
        } else {
            // Regular authentication using email and password
            if (!Auth::attempt($this->only('email', 'password'), $remember)) {
                RateLimiter::hit($this->throttleKey());

                throw ValidationException::withMessages([
                    'email' => trans('auth.failed'),
                ]);
            }
        }

        RateLimiter::clear($this->throttleKey());
    }


    /**
     * Ensure the login request is not rate limited.
     *
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited()
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     *
     * @return string
     */
    public function throttleKey()
    {
        return Str::transliterate(Str::lower($this->input('email')) . '|' . $this->ip());
    }
}
