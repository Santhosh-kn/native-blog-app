<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Facades\SecureStorage;

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

        $response = Http::baseUrl(config('api.base_url'))->acceptJson()->post('/login', $credentials);

        if ($response->failed()) {
            return redirect()->route('login')
                ->withErrors(['email' => 'These credentials do not match our records.'])
                ->onlyInput('email');
        }
        
        session(['api_token' => $response->json('access_token')]);
// SecureStorage::set('api_token', $response->json('access_token'));
        session(['api_user' => $response->json('user')]);
        
        return redirect()->route('home');
    }

    public function destroy(Request $request)
    {
        Http::baseUrl(config('api.base_url'))
            ->acceptJson()
            ->withToken(SecureStorage::get('api_token'))
            ->post('/logout');

        session()->forget(['api_user', 'device_unlocked', 'api_token']);
// SecureStorage::delete('api_token');
        $request->session()->forget(['api_user', 'device_unlocked']);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}