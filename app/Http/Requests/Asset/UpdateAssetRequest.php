<?php

namespace App\Http\Requests\Asset;

use App\Models\Asset;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssetRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');
        $asset = $this->route('asset');

        if (! $organization instanceof Organization || ! $asset instanceof Asset) {
            return false;
        }

        abort_unless($asset->organization_id === $organization->id, 404);

        return $this->user()?->can('update', $asset) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $maximum = config('opsdeck.validation.name_max_length');

        return [
            'asset_number' => ['required', 'string', 'max:'.$maximum],
            'asset_type' => ['required', Rule::in(config('opsdeck.asset_types'))],
            'manufacturer' => ['nullable', 'string', 'max:'.$maximum],
            'model' => ['nullable', 'string', 'max:'.$maximum],
            'serial_number' => ['nullable', 'string', 'max:'.$maximum],
            'os' => ['nullable', 'string', 'max:'.$maximum],
            'purchased_at' => ['nullable', 'date'],
            'purchase_cost' => ['nullable', 'numeric', 'min:0'],
            'warranty_until' => ['nullable', 'date'],
            'status' => ['required', Rule::in(config('opsdeck.asset_statuses'))],
            'location' => ['nullable', 'string', 'max:'.$maximum],
            'memo' => ['nullable', 'string'],
        ];
    }
}
