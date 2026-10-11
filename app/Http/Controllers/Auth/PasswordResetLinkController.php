<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SendPasswordResetLinkRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Throwable;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(SendPasswordResetLinkRequest $request): RedirectResponse
    {
        try {
            Password::sendResetLink($request->validated());
        } catch (Throwable $exception) {
            Log::warning('Password reset link request failed.', ['exception_class' => $exception::class]);
        }

        return back()->with('status', '該当する場合は、パスワード再設定用のメールを送信しました。メールが届かない場合は、入力したメールアドレスをご確認ください。');
    }
}
