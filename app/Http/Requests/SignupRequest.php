<?php

namespace App\Http\Requests;

use App\Enums\BusinessType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Validation for the public self-signup form. The customer enters their own
 * details; no plan is chosen here (subscriptions are custom per customer and
 * set up by the team afterwards). The card itself is captured on Cardcom's
 * hosted page, so no card data is ever validated or stored here.
 */
class SignupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise the numeric fields before validation so a business number or
     * phone typed with spaces/dashes ("05-012-3456", "51-234567-8") is judged
     * on its digits, not its punctuation.
     */
    protected function prepareForValidation(): void
    {
        $digits = fn (?string $v): ?string => $v === null ? null : preg_replace('/\D+/', '', $v);

        $this->merge(array_filter([
            'phone' => $this->filled('phone') ? $digits($this->input('phone')) : null,
            'business_number' => $this->filled('business_number') ? $digits($this->input('business_number')) : null,
        ], fn ($v) => $v !== null));
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'contact_name' => ['required', 'string', 'max:120'],
            // Israeli business/tax id is exactly 9 digits (ח.פ / ע.מ / ע.ר).
            'business_number' => ['nullable', 'digits:9'],
            'business_type' => ['required', new Enum(BusinessType::class)],
            'email' => ['required', 'email:rfc', 'max:190'],
            // Israeli phone: 9–10 digits starting with 0 (landline / mobile).
            'phone' => ['required', 'regex:/^0\d{8,9}$/'],
            'domain' => ['nullable', 'string', 'max:190'],
            'payment_method' => ['required', Rule::in(['credit_card', 'standing_order', 'bank_transfer', 'checks'])],
            'terms' => ['accepted'],
            // The drawn signature, as a PNG data URL produced by the canvas. Size
            // is bounded so a huge/forged payload can't be stored; the format is
            // pinned to PNG so only an image (never a script) is ever decoded.
            'signature' => ['required', 'string', 'max:200000', 'regex:/^data:image\/png;base64,[A-Za-z0-9+\/=\r\n]+$/'],
            // Honeypot: real users never fill this hidden field.
            'website' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'יש להזין שם.',
            'business_type.required' => 'יש לבחור סוג עסק.',
            'business_number.digits' => 'ח.פ / מספר עוסק חייב להיות 9 ספרות.',
            'email.required' => 'יש להזין כתובת מייל.',
            'email.email' => 'כתובת המייל אינה תקינה.',
            'phone.required' => 'יש להזין טלפון.',
            'phone.regex' => 'מספר הטלפון אינו תקין (מספר ישראלי, למשל 0501234567).',
            'contact_name.required' => 'יש להזין איש קשר.',
            'payment_method.required' => 'יש לבחור אמצעי תשלום.',
            'terms.accepted' => 'יש לאשר את תנאי השירות.',
            'signature.required' => 'יש לחתום בתיבת החתימה.',
            'signature.regex' => 'החתימה אינה תקינה — נסו לחתום שוב.',
            'signature.max' => 'החתימה גדולה מדי — נסו לחתום שוב.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'שם',
            'contact_name' => 'איש קשר',
            'payment_method' => 'אמצעי תשלום',
            'business_number' => 'ח.פ / עוסק',
            'business_type' => 'סוג עסק',
            'email' => 'מייל',
            'phone' => 'טלפון',
            'domain' => 'דומיין',
        ];
    }
}
