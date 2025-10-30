<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStaffRequest extends FormRequest
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
        return [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email', // Assumes you have a 'users' table
            'username' => 'required|string|unique:users,username|max:255',
            'phone' => 'required|string|max:20',
            'phone_code' => 'required|string|max:10',
            'password' => 'required|string|min:8|confirmed', // For password confirmation
            'designation' => 'nullable|string|max:255',
            'mobile' => 'nullable|string|max:20',
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
            // Add more custom messages as needed
        ];
    }
}
