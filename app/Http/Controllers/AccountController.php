<?php

namespace App\Http\Controllers;

use App\Actions\Auth\DeleteAccountAction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AccountController extends Controller
{
    public function destroy(Request $request, DeleteAccountAction $action): Response
    {
        $user = $request->user();

        // Log out and invalidate the session BEFORE deleting the user.
        // Sanctum's stateful-request resolution shares the same 'web'
        // SessionGuard-cached user instance handed back by $request->user().
        // If we deleted first, Eloquent sets exists=false on that instance,
        // and logout()'s remember-token cycling (which calls $user->save())
        // would then re-INSERT a "deleted" row instead of updating it.
        // Logging out first cycles the token via a normal UPDATE while the
        // row still exists, then the delete runs last with nothing left to
        // resurrect it.
        Auth::guard('web')->logout();

        // The `auth:sanctum` middleware authenticates via the 'web' session
        // guard, then calls Auth::shouldUse('sanctum'), making 'sanctum' the
        // default guard for the rest of the request. Sanctum's RequestGuard
        // caches the resolved user on itself the first time it is asked, so
        // an unqualified Auth::user()/Auth::check() after this point would
        // still report authenticated unless we also clear that cache.
        // Auth::forgetUser() forwards to the current default guard.
        Auth::forgetUser();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $action->execute($user);

        return response()->noContent();
    }
}
