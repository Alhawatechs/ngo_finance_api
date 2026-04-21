<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (!Schema::hasColumn('organizations', 'board_members')) {
                $table->json('board_members')->nullable();
            }
            if (!Schema::hasColumn('organizations', 'key_staff')) {
                $table->json('key_staff')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['board_members', 'key_staff']);
        });
    }
};
