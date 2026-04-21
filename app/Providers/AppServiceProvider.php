<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use App\Models\Voucher;
use App\Observers\VoucherAuditObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register Repository bindings
        $this->app->bind(
            \App\Repositories\Contracts\UserRepositoryInterface::class,
            \App\Repositories\UserRepository::class
        );

        $this->app->bind(
            \App\Repositories\Contracts\ChartOfAccountRepositoryInterface::class,
            \App\Repositories\ChartOfAccountRepository::class
        );

        $this->app->bind(
            \App\Repositories\Contracts\JournalEntryRepositoryInterface::class,
            \App\Repositories\JournalEntryRepository::class
        );

        $this->app->bind(
            \App\Repositories\Contracts\VoucherRepositoryInterface::class,
            \App\Repositories\VoucherRepository::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);
        Voucher::observe(VoucherAuditObserver::class);
    }
}
