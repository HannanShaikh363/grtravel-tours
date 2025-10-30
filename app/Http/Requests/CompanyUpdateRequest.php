<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompanyUpdateRequest extends FormRequest
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
            'company.agent_name' => ['required', 'string', 'max:255'],
            'company.phone_code_company' => ['required', 'string', 'max:10'],
            'company.agent_number' => ['required', 'string', 'max:20'],
            'company.iata_number' => ['nullable', 'string', 'max:255'],
            'company.iata_status' => ['nullable', 'string', 'max:255'],
            'company.nature_of_business' => ['nullable', 'string', 'max:255'],
            'company.agent_website' => ['nullable', 'string', 'max:255', 'url'],
            'company.address' => ['nullable', 'string', 'max:255'],
            'company.zip' => ['nullable', 'string', 'max:10'],
            'company.country_id' => ['required', 'integer'],
            'company.city_id' => ['required', 'integer'],
            'company.logo' => ['nullable', 'file', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
            'company.certificate' => ['nullable', 'file', 'mimes:pdf', 'max:2048'],
        ];
    }
}
