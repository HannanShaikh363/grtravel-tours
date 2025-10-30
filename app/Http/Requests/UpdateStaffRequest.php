<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $userId = $this->route('staff'); // Get the ID from the route parameter

        return [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'username' => [
                'required',
                'string',
                Rule::unique('users', 'username')->ignore($userId),
            ],
            'phone' => 'required|string|max:20',
            'phone_code' => 'required|string|max:10',
            'designation' => 'nullable|string|max:255',
            'mobile' => 'nullable|string|max:20',
            // Add other validation rules as needed
        ];
    }

    /**
     * Customize error messages if needed.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'email.required' => 'An email address is required.',
            'email.unique' => 'This email is already in use.',
            'username.unique' => 'This username is already taken.',
            'password.confirmed' => 'The password confirmation does not match.',
        ];
    }
}
