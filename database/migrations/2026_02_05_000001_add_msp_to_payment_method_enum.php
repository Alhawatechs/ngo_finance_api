<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE vouchers MODIFY COLUMN payment_method ENUM('cash', 'check', 'bank_transfer', 'mobile_money', 'msp') NULL");
            DB::statement("ALTER TABLE payments MODIFY COLUMN payment_method ENUM('cash', 'check', 'bank_transfer', 'mobile_money', 'msp') NOT NULL");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE vouchers MODIFY COLUMN payment_method ENUM('cash', 'check', 'bank_transfer', 'mobile_money') NULL");
            DB::statement("ALTER TABLE payments MODIFY COLUMN payment_method ENUM('cash', 'check', 'bank_transfer', 'mobile_money') NOT NULL");
        }
    }
};
