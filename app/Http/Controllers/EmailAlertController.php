<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Residents choose whether to get an email when their report's status changes.
class EmailAlertController extends ApiController
{
    // Switch from the Account page.
    public function update(Request $request): JsonResponse
    {
        if (! is_bool($request->input('enabled'))) {
            return $this->error('Choose whether to receive emails.', 422);
        }
        $user = $this->currentUser();
        $user->forceFill(['email_notifications' => $request->boolean('enabled')])->save();

        return response()->json(['success' => true, 'emailNotifications' => (bool) $user->email_notifications]);
    }

    // The link in each email opens this page; nothing changes until the button is pressed,
    // so email scanners that open links do not turn alerts off by themselves.
    public function confirm(int $user): View
    {
        $resident = User::where('role', 'resident')->findOrFail($user);

        return view('pages.email-alerts', ['done' => false, 'alreadyOff' => ! $resident->email_notifications]);
    }

    public function unsubscribe(int $user): View
    {
        $resident = User::where('role', 'resident')->findOrFail($user);
        $resident->forceFill(['email_notifications' => false])->save();

        return view('pages.email-alerts', ['done' => true, 'alreadyOff' => true]);
    }
}
