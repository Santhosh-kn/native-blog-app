<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Native\Mobile\Facades\Network;

class HomeController extends Controller
{
    public function index()
    {
        $posts = auth()->user()->posts()->latest()->paginate(10);
        $network = Network::status();

        return view('home', [
            'posts' => $posts,
            'networkConnected' => $network->connected,
            'networkType' => $network->type,
        ]);
    }
}