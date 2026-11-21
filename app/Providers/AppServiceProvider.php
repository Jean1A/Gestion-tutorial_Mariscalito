<?php

namespace App\Providers;

use App\Enums\RoleName;
use App\Models\User;
use App\Services\SchoolYearVisibility;
use App\Services\SettingsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

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
        Paginator::useBootstrapFive();
        app(SchoolYearVisibility::class)->register();
        Gate::define('coordinate-tutors', fn (User $user) => in_array($user->activeRole(), [RoleName::Administrator, RoleName::Coordinator], true) && $user->permits('tutors.coordinate') && $user->permits('students.view') && $user->permits('attendance.view'));
        Gate::define('advisor-portal', fn (User $user) => $user->activeRole() === RoleName::Tutor && $user->permits('students.view'));
        Gate::define('manage-courses', fn (User $user) => in_array($user->activeRole(), [RoleName::Administrator, RoleName::Coordinator], true) && $user->permits('courses.manage'));
        Gate::define('manage-family', fn (User $user) => in_array($user->activeRole(), [RoleName::Administrator, RoleName::Coordinator], true) && $user->permits('family.manage'));
        Gate::define('guardian-portal', fn (User $user) => $user->activeRole() === RoleName::Guardian && $user->permits('students.view'));
        Gate::define('student-tutoring', fn (User $user) => $user->activeRole() === RoleName::Student && $user->student_profile_id !== null);
        Gate::define('student-research', fn (User $user) => $user->activeRole() === RoleName::Coordinator && $user->can('coordinate-tutors'));
        Gate::define('student-tutoring-staff', fn (User $user) => $user->can('advisor-portal')
            || ($user->activeRole() === RoleName::Coordinator && $user->can('coordinate-tutors'))
            || ($user->activeRole() === RoleName::Teacher && $user->permits('courses.record')));
        foreach (['academics', 'assignments', 'settings'] as $area) {
            Gate::define('manage-'.$area, fn (User $user) => ($user->activeRole() === RoleName::Administrator || ($area !== 'settings' && $user->activeRole() === RoleName::Coordinator)) && $user->permits($area === 'assignments' ? 'students.manage' : $area.'.manage'));
        }
        RateLimiter::for('recovery', function (Request $request) {
            $settings = app(SettingsService::class);

            return Limit::perMinutes((int) $settings->required('security.lock_minutes'), (int) $settings->required('security.login_attempts'))
                ->by('recovery:'.hash('sha256', $request->ip()))
                ->response(fn () => response('Demasiados intentos. Intenta nuevamente más tarde.', 429));
        });
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
