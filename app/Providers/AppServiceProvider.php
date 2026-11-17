<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use App\Policies\ActivityPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('viewReports', function (User $user): bool {
            $currentUser = $user->fresh();

            return $currentUser !== null && $currentUser->is_active && $currentUser->role === UserRole::Admin;
        });
        Gate::policy(Activity::class, ActivityPolicy::class);
    }
}
