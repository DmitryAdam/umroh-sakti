<?php

namespace App\Providers;

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

    public function boot(): void
    {
        // Satu-satunya izin di aplikasi ini. Dipasang sebagai `can:admin` di
        // routes/web.php (bukan cek per method) supaya method baru di controller
        // yang sudah ada ikut terkunci tanpa perlu diingat, dan dipakai `@can` di
        // Blade untuk menyembunyikan menunya.
        Gate::define('admin', fn (User $user) => $user->isAdmin());
    }
}
