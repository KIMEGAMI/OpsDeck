<?php

namespace App\Http\Requests;

use App\Models\Organization;
use App\Models\SavedView;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DeleteSavedViewRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');
        $membershipRole = $this->attributes->get('currentMembership')?->role?->value;
        $savedView = $this->route('savedView');

        if (! $organization instanceof Organization || ! $savedView instanceof SavedView || $savedView->organization_id !== $organization->id) {
            return false;
        }

        if ($savedView->is_shared) {
            return in_array($membershipRole, ['owner', 'admin'], true);
        }

        return $savedView->created_by === $this->user()?->id;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }
}
