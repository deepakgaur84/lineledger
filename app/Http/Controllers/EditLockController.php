<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\EditLocks\EditLockManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The browser keeper's lease endpoints (resources/js/edit-lock.js).
 *
 * Registered outside the {company} prefix on purpose: EnsureCompanyMembership
 * rewrites the user's current company on every request, so a 30-second
 * heartbeat from an edit tab in one company would keep flipping the current
 * company of a tab open on another. A lease is addressed by its secret token
 * and must belong to the signed-in user; the manager also checks the user is
 * still a member of the lock's company.
 */
class EditLockController extends Controller
{
    public function heartbeat(Request $request, EditLockManager $locks): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:40'],
            'active' => ['sometimes', 'boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();

        return response()->json($locks->heartbeat($data['token'], (bool) ($data['active'] ?? false), $user));
    }

    /**
     * navigator.sendBeacon posts form data on leave; always 204.
     */
    public function release(Request $request, EditLockManager $locks): Response
    {
        $token = $request->input('token');

        /** @var User $user */
        $user = $request->user();

        if (is_string($token) && strlen($token) === 40) {
            $locks->release($token, $user);
        }

        return response()->noContent();
    }
}
