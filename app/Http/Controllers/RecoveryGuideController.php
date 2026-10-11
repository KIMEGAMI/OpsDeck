<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class RecoveryGuideController extends Controller
{
    public function show(Request $request, string $type): View
    {
        abort_unless(array_key_exists($type, config('opsdeck.recovery_guides', [])), 404);
        $guide = config('opsdeck.recovery_guides.'.$type);

        return view('recovery-guides.show', compact('guide', 'type'));
    }
}
