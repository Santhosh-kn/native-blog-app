<?php

namespace App\Http\Controllers;

use App\Support\FirebaseRemoteConfigDemo;
use Illuminate\Http\Request;
use Native\Mobile\Facades\Network;

class HomeController extends Controller
{
    public function index(FirebaseRemoteConfigDemo $remoteConfig)
    {
        $posts = auth()->user()->posts()->latest()->paginate(10);
        $network = Network::status();

        return view('home', [
            'posts' => $posts,
            'remoteConfig' => $remoteConfig->snapshot(),
            'networkConnected' => $network->connected,
            'networkType' => $network->type,
        ]);
    }
}