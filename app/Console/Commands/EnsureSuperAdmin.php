<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;

class EnsureSuperAdmin extends Command
{
    protected $signature = 'users:ensure-super-admin {--email=admin@aada.org.af : Email of the user to promote}';
    protected $description = 'Ensure the specified user (default: Ahmad Dost) has Super Administrator role and can_manage_all_offices';

    public function handle(): int
    {
        $email = $this->option('email');
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("User with email '{$email}' not found.");
            return self::FAILURE;
        }

        $superAdminRole = Role::where('name', 'super-admin')
            ->where('organization_id', $user->organization_id)
            ->first();

        if (!$superAdminRole) {
            $this->error('Super Administrator role not found. Run database seeder first.');
            return self::FAILURE;
        }

        $updated = false;

        if (!$user->hasRole('super-admin')) {
            $user->assignRole($superAdminRole);
            $this->info("Assigned Super Administrator role to {$user->name} ({$user->email}).");
            $updated = true;
        }

        if (!($user->can_manage_all_offices ?? false)) {
            $user->update(['can_manage_all_offices' => true]);
            $this->info("Set can_manage_all_offices for {$user->name}.");
            $updated = true;
        }

        if (!$updated) {
            $this->info("{$user->name} ({$user->email}) is already configured as Super Administrator.");
        }

        return self::SUCCESS;
    }
}
