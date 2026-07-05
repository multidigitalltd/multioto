<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the public "contact us" support form.
 */
class FormTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:5000'],
            // Honeypot: real users never fill this hidden field.
            'website' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'יש להזין שם.',
            'email.required' => 'יש להזין כתובת מייל.',
            'email.email' => 'כתובת המייל אינה תקינה.',
            'subject.required' => 'יש להזין נושא.',
            'message.required' => 'יש להזין תוכן לפנייה.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'שם',
            'email' => 'מייל',
            'phone' => 'טלפון',
            'subject' => 'נושא',
            'message' => 'תוכן הפנייה',
        ];
    }
}
