<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Bbs\Biometric\Facades\Biometric;
use Illuminate\Support\Facades\Cache;

class UnlockController extends Controller
{
    public function show()
    {
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        return view('unlock');
    }

    public function confirm(Request $request)
    {
        session(['device_unlocked' => true]);

        return redirect()->route('home');
    }

    public function triggerBiometric()
    {
        Cache::forget('biometric_result');

        Biometric::authenticate('Unlock App', 'Confirm your identity to continue');

        return response()->json(['triggered' => true]);
    }

    public function biometricStatus()
    {
        $result = Cache::get('biometric_result');

        \Log::info('Polled biometric status', ['result' => $result]);

        return response()->json([
            'result' => $result,
        ]);
    }
}