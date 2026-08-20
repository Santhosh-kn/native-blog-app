<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Bbs\FirebaseGoogleAuth\Facades\FirebaseGoogleAuth;
use Throwable;

class LoginController extends Controller
{
    public function create()
    {
        return view('login');
    }

    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            return redirect()->route('login')
                ->withErrors(['email' => 'These credentials do not match our records.'])
                ->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect('/');
    }

    public function destroy(Request $request)
    {
        $user = Auth::user();

        if ($user?->firebase_uid) {
            try {
                FirebaseGoogleAuth::signOut();
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        Auth::logout();

        $request->session()->forget('device_unlocked');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}