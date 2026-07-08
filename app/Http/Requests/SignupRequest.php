<?php

namespace App\Http\Requests;

use App\Enums\BusinessType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Validation for the public self-signup form. The customer picks a plan and
 * enters their own details; the card itself is captured afterwards on Cardcom's
 * hosted page, so no card data is ever validated or stored here.
 */
class SignupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'business_number' => ['nullable', 'string', 'max:32'],
            'business_type' => ['required', new Enum(BusinessType::class)],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['required', 'string', 'max:32'],
            'domain' => ['nullable', 'string', 'max:190'],
            'plan_id' => ['required', Rule::exists('plans', 'id')->where('active', true)],
            'terms' => ['accepted'],
            // Honeypot: real users never fill this hidden field.
            'website' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'יש להזין שם.',
            'business_type.required' => 'יש לבחור סוג עסק.',
            'email.required' => 'יש להזין כתובת מייל.',
            'email.email' => 'כתובת המייל אינה תקינה.',
            'phone.required' => 'יש להזין טלפון.',
            'plan_id.required' => 'יש לבחור מסלול.',
            'plan_id.exists' => 'המסלול שנבחר אינו זמין.',
            'terms.accepted' => 'יש לאשר את תנאי השירות.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'שם',
            'business_number' => 'ח.פ / עוסק',
            'business_type' => 'סוג עסק',
            'email' => 'מייל',
            'phone' => 'טלפון',
            'domain' => 'דומיין',
            'plan_id' => 'מסלול',
        ];
    }
}
