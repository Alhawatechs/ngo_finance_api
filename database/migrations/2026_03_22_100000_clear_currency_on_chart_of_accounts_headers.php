<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Header (non-posting) accounts do not use account currency; only posting leaf accounts do.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('chart_of_accounts')
            ->where(function ($q) {
                $q->where('is_header', true)->orWhere('is_posting', false);
            })
            ->update(['currency_code' => null]);
    }

    public function down(): void
    {
        // Cannot restore previous currency values; posting accounts unchanged.
    }
};
