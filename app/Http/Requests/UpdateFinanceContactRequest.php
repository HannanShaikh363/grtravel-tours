<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFinanceContactRequest extends FormRequest
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
            'financeContact.account_name' => ['required', 'string', 'max:255'],
            'financeContact.account_email' => ['required', 'email', 'max:255'],
            'financeContact.phone_code_finance' => ['required', 'string', 'max:5'],
            'financeContact.account_contact' => ['required', 'digits_between:7,15'],
            'financeContact.account_code' => ['required', 'string', 'max:20'],
            'financeContact.sales_account_code' => ['required', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'financeContact.account_name.required' => 'Account Name is required.',
            'financeContact.account_email.required' => 'Email is required.',
            'financeContact.account_email.email' => 'Enter a valid email address.',
            'financeContact.phone_code_finance.required' => 'Phone Code is required.',
            'financeContact.account_contact.required' => 'Contact Number is required.',
            'financeContact.account_code.required' => 'Account Code is required.',
            'financeContact.sales_account_code.required' => 'Sales Account Code is required.',
        ];
    }
}
