<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AnnouncementRecipientCountRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null && in_array(strtolower((string) $this->user()->email), array_map('strtolower', config('opsdeck.platform_admin_emails', [])), true);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'organization_ids' => ['nullable', 'array'],
            'organization_ids.*' => ['integer', Rule::exists('organizations', 'id')],
        ];
    }
}
