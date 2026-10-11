<?php

namespace App\Http\Requests\AssetAssignment;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssetAssignmentRequest extends FormRequest
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
            'asset_id' => ['required', 'integer'],
            'employee_id' => ['required', 'integer'],
            'assigned_at' => ['required', 'date'],
            'note' => ['nullable', 'string'],
        ];
    }
}
