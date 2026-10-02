<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactRequest;
use App\Notifications\NewContactRequestNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class ContactRequestController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:190'],
            'country' => ['required', 'string', 'size:2', 'alpha'],
            // International format: "+" and digits only — no letters or spaces.
            'phone' => ['required', 'string', 'regex:/^\+\d{7,15}$/'],
            // Honeypot: real visitors never see this field.
            'website' => ['prohibited'],
        ]);

        $contact = ContactRequest::create([
            ...$data,
            'email' => strtolower($data['email']),
            'country' => strtoupper($data['country']),
            'locale' => app()->getLocale(),
            'ip' => $request->ip(),
        ]);

        if ($to = config('chat.notify_email')) {
            Notification::route('mail', $to)->notify(new NewContactRequestNotification($contact));
        }

        return response()->json(['ok' => true], 201);
    }
}
