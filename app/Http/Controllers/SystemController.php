<?php

namespace App\Http\Controllers;

use Native\Mobile\Facades\System;

class SystemController extends Controller
{
    public function openSettings()
    {
        System::appSettings();

        return back();
    }
}