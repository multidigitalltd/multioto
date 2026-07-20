<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stores/removes a team member's browser push subscription. The browser holds
 * the actual permission; this only records the endpoint + keys we need to send a
 * Web Push to that device. Team-only (panel auth) and scoped to the current user.
 */
class PushSubscriptionController extends Controller
{
    /** Save (or refresh) the current user's push subscription for this browser. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'contentEncoding' => ['nullable', 'string', 'max:50'],
        ]);

        $request->user()->updatePushSubscription(
            $data['endpoint'],
            $data['keys']['p256dh'],
            $data['keys']['auth'],
            $data['contentEncoding'] ?? null,
        );

        return response()->json(['ok' => true]);
    }

    /** Forget this browser's subscription (the member turned notifications off). */
    public function destroy(Request $request): JsonResponse
    {
        $endpoint = (string) $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
        ])['endpoint'];

        $request->user()->deletePushSubscription($endpoint);

        return response()->json(['ok' => true]);
    }
}
