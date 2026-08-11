<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Facades\SecureStorage;
use Native\Mobile\Facades\Device;
use native\Mobile\Facades\Dialog;

class UserController extends Controller
{
    public function edit($id)
    {
        // $response = Http::baseUrl(config('api.base_url'))->acceptJson()->withToken(SecureStorage::get('api_token'))->get("/users/{$id}");
        $response = Http::baseUrl(config('api.base_url'))->acceptJson()->withToken(session('api_token'))->get("/users/{$id}");

        if ($response->failed()) {
            abort(404);
        }

        return view('users.edit', ['user' => $response->json()]);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'min:4'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        // $response = Http::baseUrl(config('api.base_url'))->acceptJson()->withToken(SecureStorage::get('api_token'))->put("/users/{$id}", $validated);
        $response = Http::baseUrl(config('api.base_url'))->acceptJson()->withToken(session('api_token'))->put("/users/{$id}", $validated);

        if ($response->failed()) {
            return back()
                ->withErrors($response->json('errors') ?? ['email' => 'Update failed.'])
                ->withInput();
        }
        Device::vibrate();
        Dialog::toast("User updated successfully.");
        return redirect()->route('home');
    }

    public function destroy($id)
    {
        $response = Http::baseUrl(config('api.base_url'))
            ->acceptJson()
            // ->withToken(SecureStorage::get('api_token'))
            ->withToken(session('api_token'))
            ->delete("/users/{$id}");

        if ($response->failed()) {
            return redirect()->route('home')
                ->withErrors(['delete' => $response->json('message') ?? 'Delete failed.']);
        }

        Device::vibrate();
        Dialog::toast('User deleted.');

        return redirect()->route('home');
    }
}