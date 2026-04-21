<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add 'planning' and 'cancelled' to projects.status enum so API/frontend values are accepted.
     */
    public function up(): void
    {
        // MySQL: modify enum to include new values
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

    /**
     * Revert to original enum (drop planning, cancelled).
     */
    public function down(): void
    {
        // Convert planning -> draft, cancelled -> closed before reverting
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
