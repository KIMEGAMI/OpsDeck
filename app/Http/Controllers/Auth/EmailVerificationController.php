<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EmailVerificationController extends Controller
{
    public function notice(Request $request): RedirectResponse|Response
    {
        if ($request->user()?->hasVerifiedEmail()) {
            return to_route('dashboard');
        }

        return response()
            ->view('auth.verify-email')
            ->header('Cache-Control', 'private, no-store');
    }

    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        return to_route('dashboard')->with('status', 'メールアドレスを確認しました。');
    }

    public function resend(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return to_route('dashboard');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', '確認メールを再送しました。受信トレイをご確認ください。');
    }
}
