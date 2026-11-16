<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireMultiFactorAuthentication
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $panel = Filament::getCurrentOrDefaultPanel();

        if ($panel === null) {
            abort(403);
        }

        if (! $panel->isMultiFactorAuthenticationRequired() || $request->routeIs(
            $panel->getSetUpRequiredMultiFactorAuthenticationRouteName(),
            $panel->generateRouteName('auth.logout'),
        )) {
            return $next($request);
        }

        $user = Filament::auth()->user();

        if ($user instanceof User) {
            $user->refresh();
            abort_unless($user->canAccessPanel($panel), 403);
        }

        return app(EnsureMultiFactorAuthenticationIsEnabled::class)->handle($request, $next);
    }
}
