<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        $response = Http::baseUrl(config('api.base_url'))
            ->acceptJson()
            ->withToken(session('api_token'))
            ->get('/users', ['page' => $request->query('page', 1)]);

        $data = $response->json();

        return view('home', [
            'users' => $data['data'] ?? [],
            'meta' => [
                'current_page' => $data['current_page'] ?? 1,
                'last_page' => $data['last_page'] ?? 1,
            ],
        ]);
    }
}