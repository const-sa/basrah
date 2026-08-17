<?php

namespace App\Providers;

use App\Http\Controllers\Admin\RolesController;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
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
        // المدير العام يتجاوز كل الفحوصات.
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        // تسجيل بوابة (Gate) لكل صلاحية: يمكن استخدام can('clients.create') أو @can في الواجهة.
        foreach (RolesController::permissionKeys() as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }
    }
}
