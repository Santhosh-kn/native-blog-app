<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Native\Mobile\Facades\PushNotifications;
use Lumi\NativePush\Server\FcmSender;

class PushController extends Controller
{
    public function index()
    {
        return view('push', [
            'permission' => PushNotifications::checkPermission(),
            'token' => auth()->user()->push_token,
        ]);
    }

    public function enroll()
    {
        PushNotifications::enroll();

        return redirect()->route('push.index');
    }

    public function status()
    {
        $token = PushNotifications::getToken();

        if ($token) {
            auth()->user()->update(['push_token' => $token]);
        }

        return response()->json([
            'token' => $token,
        ]);
    }

    public function sendTest()
    {
        $token = auth()->user()->push_token;
        $credentialsPath = $this->ensureCredentialsFile();

        if (! $token) {
            return back()->withErrors(['push' => 'No token available yet — enroll first.']);
        }

        try {
            $sender = new FcmSender(config('push.project_id'), $credentialsPath);

            $sender->notify(
                $token,
                'Test Notification',
                'This is a real push from your blog app!'
            );

            return redirect()->route('push.index')->with('status', 'Notification sent!');
        } catch (\Exception $e) {
            return back()->withErrors(['push' => 'Send failed: ' . $e->getMessage()]);
        }
    }

    private function ensureCredentialsFile(): string
    {
        $path = storage_path('app/firebase-service-account.json');

        if (! is_file($path)) {
            $encoded = env('FIREBASE_CREDENTIALS_B64');

            if (! $encoded) {
                throw new \RuntimeException('FIREBASE_CREDENTIALS_B64 is not set in .env');
            }

            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }

            file_put_contents($path, base64_decode($encoded));
        }

        return $path;
    }
}