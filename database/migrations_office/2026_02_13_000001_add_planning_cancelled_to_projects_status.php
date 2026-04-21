<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add 'planning' and 'cancelled' to projects.status enum (office schema).
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE projects MODIFY COLUMN status ENUM(
            'draft',
            'planning',
            'pending_approval',
            'approved',
            'active',
            'on_hold',
            'completed',
            'cancelled',
            'closed'
        ) DEFAULT 'draft'");
    }

    public function down(): void
    {
        DB::table('projects')->where('status', 'planning')->update(['status' => 'draft']);
        DB::table('projects')->where('status', 'cancelled')->update(['status' => 'closed']);

        DB::statement("ALTER TABLE projects MODIFY COLUMN status ENUM(
            'draft',
            'pending_approval',
            'approved',
            'active',
            'on_hold',
            'completed',
            'closed'
        ) DEFAULT 'draft'");
    }
};
