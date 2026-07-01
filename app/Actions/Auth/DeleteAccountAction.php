<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteAccountAction
{
    public function execute(User $user): void
    {
        DB::transaction(function () use ($user) {
            if ($user->subscribed('default')) {
                $user->subscription('default')->cancelNow();
            }
            $user->delete();
        });
    }
}
