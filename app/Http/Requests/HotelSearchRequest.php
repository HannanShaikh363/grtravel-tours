<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HotelSearchRequest extends FormRequest
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
        $rules = [
            'city.name' => 'required|string',
            'city.id' => 'required|integer',
            'city.country_code' => 'required|string|size:2',
            'check_in_out' => ['required', 'regex:/^\d{4}-\d{2}-\d{2} to \d{4}-\d{2}-\d{2}$/'],
            'rooms' => 'required|integer|min:1',
            'adult_capacity' => 'required|array',
            'adult_capacity.*' => 'required|integer|min:1',
            'child_capacity' => 'required|array',
            // 'child_capacity.*' => 'required|integer|min:0',
            // 'child_ages' => 'required|array',
            // 'child_ages.*' => 'required|array',
            // 'child_ages.*.*' => 'required|integer|min:0|max:17',
            'country.name' => 'required|string',
            'country.country_code' => 'required|string|size:2',
            'currency' => 'required|string|size:3',
        ];

        

        return $rules;
    }

    public function messages(): array
    {
        return [
            'check_in_out.regex' => 'The check_in_out must be in format YYYY-MM-DD to YYYY-MM-DD.',
        ];
    }
}
