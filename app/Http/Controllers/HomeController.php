<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index()
    {
        $posts = auth()->user()->posts()->latest()->paginate(10);

        return view('home', [
            'posts' => $posts,
        ]);
    }
}