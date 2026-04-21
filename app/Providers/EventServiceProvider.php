<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;
use App\Events\VoucherCreated;
use App\Events\VoucherApproved;
use App\Events\VoucherRejected;
use App\Events\PaymentProcessed;
use App\Events\BudgetExceeded;
use App\Listeners\LogVoucherActivity;
use App\Listeners\SendVoucherNotification;
use App\Listeners\UpdateBudgetUtilization;
use App\Listeners\SendBudgetAlert;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        VoucherCreated::class => [
            LogVoucherActivity::class,
            SendVoucherNotification::class,
        ],
        VoucherApproved::class => [
            LogVoucherActivity::class,
            SendVoucherNotification::class,
            UpdateBudgetUtilization::class,
        ],
        VoucherRejected::class => [
            LogVoucherActivity::class,
            SendVoucherNotification::class,
        ],
        PaymentProcessed::class => [
            LogVoucherActivity::class,
            UpdateBudgetUtilization::class,
        ],
        BudgetExceeded::class => [
            SendBudgetAlert::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
