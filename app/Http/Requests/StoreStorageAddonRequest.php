<?php

namespace App\Http\Requests;

use App\Models\Organization;
use App\Services\StorageAddonCapacityService;
use Illuminate\Foundation\Http\FormRequest;

class StoreStorageAddonRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->attributes->get('currentOrganization');

        return $organization instanceof Organization
            && $this->user()?->can('manageBilling', $organization) === true;
    }

    public function rules(): array
    {
        $maximumQuantity = app(StorageAddonCapacityService::class)->maximumQuantity();

        return [
            'quantity' => ['required', 'integer', 'min:0', 'max:'.($maximumQuantity ?? 0)],
            'confirm_change' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'quantity.required' => '変更後の追加容量を入力してください。',
            'quantity.integer' => '追加容量は整数で入力してください。',
            'quantity.min' => '追加容量は0GiB以上で指定してください。',
            'quantity.max' => '設定された追加容量の上限を超えています。',
            'confirm_change.accepted' => '容量の変更内容を確認してから申し込んでください。',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['quantity' => '追加容量'];
    }
}
