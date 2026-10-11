<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCancellationFeedbackRequest;
use App\Models\CancellationFeedback;
use Illuminate\Http\RedirectResponse;

class CancellationFeedbackController extends Controller
{
    public function store(StoreCancellationFeedbackRequest $request): RedirectResponse
    {
        CancellationFeedback::query()->create(['organization_id' => $request->attributes->get('currentOrganization')->id, 'user_id' => $request->user()->id, ...$request->validated()]);

        return to_route('billing.index')->with('status', '解約理由を受け付けました。');
    }
}
