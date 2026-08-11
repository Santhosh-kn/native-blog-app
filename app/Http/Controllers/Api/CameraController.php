<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Native\Mobile\Facades\Camera;


class CameraController extends Controller
{
    public function capture(){
        try{
            $photo = Camera::getPhoto([
                'quality' => 100,
                'resultType' => 'base64'
            ]);

            session(['temp_photo_base64' => $photo]);
            return response()->json([
                'success' => true,
                'photo' => $photo
            ]);
        } catch(\Exception $e){
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}

