<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Seeder;

/**
 * Demo in-app notifications for the notification bell / slide panel.
 * Idempotent: removes prior rows tagged data.source = sample_notifications for the target user, then inserts.
 *
 * php artisan db:seed --class=SampleNotificationsSeeder
 */
class SampleNotificationsSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->where('email', 'admin@aada.org.af')->first()
            ?? User::query()->orderBy('id')->first();

        if (! $user) {
            $this->command?->warn('SampleNotificationsSeeder: no user found; skipping.');

            return;
        }

        UserNotification::query()
            ->where('user_id', $user->id)
            ->where('data->source', 'sample_notifications')
            ->delete();

        $marker = ['source' => 'sample_notifications'];
        $t = now();

        $samples = [
            [
                'type' => 'approval',
                'title' => 'Voucher awaiting your approval',
                'message' => 'Payment voucher PV-2024-0842 for $12,500 USD is pending your approval (L2 Finance Manager).',
                'action_url' => '/approvals/vouchers',
                'is_read' => false,
                'read_at' => null,
                'created_at' => $t->copy()->subMinutes(25),
            ],
            [
                'type' => 'approval',
                'title' => 'Budget revision submitted',
                'message' => 'Q2 program budget revision for Education Cluster has been submitted for review.',
                'action_url' => '/approvals/budgets',
                'is_read' => false,
                'read_at' => null,
                'created_at' => $t->copy()->subHours(2),
            ],
            [
                'type' => 'budget',
                'title' => 'Budget utilization at 78%',
                'message' => 'Project PRJ-2024-011 is at 78% of approved YTD budget. Review line items before new commitments.',
                'action_url' => '/budget/tracking',
                'is_read' => true,
                'read_at' => $t->copy()->subHour(),
                'created_at' => $t->copy()->subHours(5),
            ],
            [
                'type' => 'treasury',
                'title' => 'Bank reconciliation due',
                'message' => 'Main operating account (USD) has unreconciled items older than 5 business days.',
                'action_url' => '/treasury/bank/accounts',
                'is_read' => false,
                'read_at' => null,
                'created_at' => $t->copy()->subHours(8),
            ],
            [
                'type' => 'treasury',
                'title' => 'Cash position summary',
                'message' => 'Weekly cash position across offices is available. Petty cash KBL is below minimum threshold.',
                'action_url' => '/treasury/cash',
                'is_read' => true,
                'read_at' => $t->copy()->subHours(12),
                'created_at' => $t->copy()->subHours(26),
            ],
            [
                'type' => 'success',
                'title' => 'Journal posted successfully',
                'message' => 'Journal batch JB-2024-0315 was posted to the general ledger with 14 lines.',
                'action_url' => '/general-ledger/journal-entries',
                'is_read' => true,
                'read_at' => $t->copy()->subDay(),
                'created_at' => $t->copy()->subDay(),
            ],
            [
                'type' => 'warning',
                'title' => 'Fiscal period closing soon',
                'message' => 'March 2026 period closes in 3 days. Complete accruals and bank reconciliations before lock.',
                'action_url' => '/general-ledger/period-close',
                'is_read' => false,
                'read_at' => null,
                'created_at' => $t->copy()->subHours(30),
            ],
            [
                'type' => 'info',
                'title' => 'Notification preferences',
                'message' => 'You can manage email alerts and in-app notifications under Settings → Notifications.',
                'action_url' => '/settings/notifications',
                'is_read' => false,
                'read_at' => null,
                'created_at' => $t->copy()->subHours(48),
            ],
        ];

        foreach ($samples as $row) {
            $createdAt = $row['created_at'];
            $notification = UserNotification::create([
                'user_id' => $user->id,
                'type' => $row['type'],
                'title' => $row['title'],
                'message' => $row['message'],
                'action_url' => $row['action_url'],
                'data' => $marker,
                'is_read' => $row['is_read'],
                'read_at' => $row['read_at'] ?? null,
            ]);
            $notification->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->saveQuietly();
        }

        $this->command?->info('SampleNotificationsSeeder: inserted '.count($samples).' notifications for user '.$user->email.'.');
    }
}
