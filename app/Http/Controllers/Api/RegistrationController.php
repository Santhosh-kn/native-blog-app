<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Facades\SecureStorage;

class RegistrationController extends Controller
{
    public function create()
    {
        return view('register');
    }

   public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'min:4'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $validated['password_confirmation'] = $request->input('password_confirmation');

        // $response = Http::baseUrl(config('api.base_url'))->post('/register', $validated);
        $response = Http::baseUrl(config('api.base_url'))->acceptJson()->post('/register', $validated);

        if ($response->failed()) {
            return back()
                ->withErrors($response->json('errors') ?? ['email' => 'Registration failed.'])
                ->withInput();
        }

        // session(['api_token' => $response->json('access_token')]);
        // session(['api_user' => $response->json('user')]);
        session(['api_token' => $response->json('access_token')]);
// SecureStorage::set('api_token', $response->json('access_token'));
        session(['api_user' => $response->json('user')]);

        return redirect()->route('home');
    }
}