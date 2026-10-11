<?php

namespace App\Http\Requests\AssetAssignment;

use App\Models\AssetAssignment;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReturnAssetAssignmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'returned_at' => ['required', 'date'],
            'note' => ['nullable', 'string'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('returned_at')) {
                return;
            }

            $assetAssignment = $this->route('assetAssignment');

            if (! $assetAssignment instanceof AssetAssignment) {
                return;
            }

            $organization = $this->attributes->get('currentOrganization');

            if (! $organization instanceof Organization || $assetAssignment->organization_id !== $organization->id) {
                return;
            }

            if ($this->date('returned_at')->isBefore($assetAssignment->assigned_at)) {
                $validator->errors()->add('returned_at', '返却日は割当日以降の日付を指定してください。');
            }
        }];
    }
}
