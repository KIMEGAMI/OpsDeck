<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class LegalController extends Controller
{
    public function terms(): View
    {
        return view('legal.terms');
    }

    public function privacy(): View
    {
        return view('legal.privacy');
    }

    public function commercialTransactions(): View
    {
        return view('legal.commercial-transactions', [
            'contactEmail' => config('opsdeck.support_email'),
            'seller' => config('opsdeck.commercial_information'),
        ]);
    }
}
