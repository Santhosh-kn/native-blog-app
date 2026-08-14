<?php

namespace App\Http\Controllers;

use Native\Mobile\Facades\Microphone;

class MicrophoneController extends Controller
{
    public function index()
    {
        return view('microphone');
    }

    public function start()
    {
        Microphone::record()->start();

        return redirect()->route('microphone.index');
    }

    public function stop()
    {
        Microphone::stop();

        return redirect()->route('microphone.index');
    }

    public function status()
    {
        return response()->json([
            'status' => Microphone::getStatus(),
            'recording' => Microphone::getRecording(),
        ]);
    }
}