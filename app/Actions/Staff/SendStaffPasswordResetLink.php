<?php

namespace App\Actions\Staff;

use App\Models\User;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

class SendStaffPasswordResetLink
{
    public function handle(User $actor, User $staff): string
    {
        Gate::forUser($actor)->authorize('update', $staff);
        $staff->refresh();

        if (! $staff->is_active) {
            throw ValidationException::withMessages(['staff' => 'Password reset links cannot be sent to inactive staff.']);
        }

        $panel = Filament::getPanel('admin');

        return Password::broker($panel->getAuthPasswordBroker())->sendResetLink(
            ['email' => $staff->email],
            function (User $user, #[SensitiveParameter] string $token) use ($panel): void {
                if (! $user->canAccessPanel($panel)) {
                    throw ValidationException::withMessages(['staff' => 'Password reset links cannot be sent to inactive staff.']);
                }

                $notification = app(ResetPassword::class, ['token' => $token]);
                $notification->url = $panel->getResetPasswordUrl($token, $user);
                $user->notify($notification->afterCommit());
                event(new PasswordResetLinkSent($user));
            },
        );
    }
}
