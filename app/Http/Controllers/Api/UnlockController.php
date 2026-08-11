<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Native\Mobile\Facades\SecureStorage;

class UnlockController extends Controller
{
    public function show()
    {
        // if (! SecureStorage::get('api_token')) {
        //     return redirect()->route('login');
        // }
        if (! session('api_token')) {
            return redirect()->route('login');
        }
        return view('unlock');
    }

    public function confirm(Request $request)
    {
        session(['device_unlocked' => true]);

        return redirect()->route('home');
    }
}