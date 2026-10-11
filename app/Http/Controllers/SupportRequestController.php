<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSupportRequestRequest;
use App\Http\Requests\UpdateSupportRequestRequest;
use App\Models\SupportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SupportRequestController extends Controller
{
    public function create(Request $request): View
    {
        $errorReference = $request->query('error_reference');
        if (! is_string($errorReference) || ! Str::isUuid($errorReference)) {
            $errorReference = '';
        }

        return view('support.create', compact('errorReference'));
    }

    public function store(StoreSupportRequestRequest $request): RedirectResponse
    {
        $supportRequest = SupportRequest::query()->create([
            'organization_id' => $request->attributes->get('currentOrganization')?->id,
            'user_id' => $request->user()?->id,
            'reference_code' => 'SUP-'.Str::upper((string) Str::uuid()),
            ...$request->validated(),
        ]);

        return to_route('support.create')->with([
            'status' => 'お問い合わせを受け付けました。',
            'support_reference_code' => $supportRequest->reference_code,
        ]);
    }

    public function adminIndex(): View
    {
        $filters = request()->validate([
            'status' => ['nullable', Rule::in(['open', 'in_progress', 'resolved'])],
            'reference_code' => ['nullable', 'string', 'max:40'],
            'error_reference' => ['nullable', 'uuid:4'],
        ]);
        $status = $filters['status'] ?? null;
        $referenceCode = isset($filters['reference_code']) ? Str::upper(trim($filters['reference_code'])) : null;
        $errorReference = isset($filters['error_reference']) ? Str::lower(trim($filters['error_reference'])) : null;
        $requests = SupportRequest::query()
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($referenceCode, fn ($query) => $query->where('reference_code', $referenceCode))
            ->when($errorReference, fn ($query) => $query->where('error_reference', $errorReference))
            ->latest()
            ->orderByDesc('id')
            ->paginate(config('opsdeck.pagination.per_page'))
            ->withQueryString();

        return view('admin.support.index', compact('requests', 'status', 'errorReference'));
    }

    public function update(UpdateSupportRequestRequest $request, SupportRequest $supportRequest): RedirectResponse
    {
        $supportRequest->update(['status' => $request->string('status')->toString()]);

        return to_route('admin.support.index')->with('status', 'お問い合わせの状態を更新しました。');
    }
}
