<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class GoogleAuthController extends Controller
{
    public function verify(Request $request)
    {
        $idToken = $request->input('id_token');

        $response = Http::baseUrl(config('api.base_url'))
            ->acceptJson()
            ->post('/auth/google/verify', [
                'id_token' => $idToken,
            ]);

        if ($response->failed()) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication failed',
            ], 401);
        }

        $data = $response->json();

        session([
            'api_token' => $data['access_token'],
            'api_user' => $data['user'],
        ]);

        return response()->json([
            'success' => true,
            'redirect' => '/',
        ]);
    }
}