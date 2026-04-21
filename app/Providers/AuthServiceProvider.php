<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\User;
use App\Models\Voucher;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Policies\VoucherPolicy;
use App\Policies\JournalEntryPolicy;
use App\Policies\ProjectPolicy;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Voucher::class => VoucherPolicy::class,
        JournalEntry::class => JournalEntryPolicy::class,
        Project::class => ProjectPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        // Super Admin can do everything
        Gate::before(function (User $user, string $ability) {
            if ($user->hasRole('super-admin')) {
                return true;
            }
        });

        // Define gates for specific permissions
        Gate::define('manage-users', function (User $user) {
            return $user->hasPermissionTo('manage-users');
        });

        Gate::define('manage-roles', function (User $user) {
            return $user->hasPermissionTo('manage-roles');
        });

        Gate::define('manage-chart-of-accounts', function (User $user) {
            return $user->hasPermissionTo('manage-chart-of-accounts')
                || (
                    $user->hasPermissionTo('edit-chart-of-accounts')
                    && $user->hasPermissionTo('delete-chart-of-accounts')
                );
        });

        Gate::define('create-voucher', function (User $user) {
            return $user->hasPermissionTo('create-voucher');
        });

        Gate::define('approve-voucher', function (User $user, int $level = 1) {
            return $user->hasPermissionTo("approve-voucher-level-{$level}");
        });

        Gate::define('view-reports', function (User $user) {
            return $user->hasPermissionTo('view-reports');
        });

        Gate::define('export-reports', function (User $user) {
            return $user->hasPermissionTo('export-reports');
        });

        Gate::define('manage-budgets', function (User $user) {
            return $user->hasPermissionTo('manage-budgets');
        });

        Gate::define('manage-projects', function (User $user) {
            return $user->hasPermissionTo('manage-projects');
        });
    }
}
