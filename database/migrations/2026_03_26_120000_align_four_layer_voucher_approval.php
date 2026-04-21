<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aligns legacy data with the four-layer finance approval ladder (L1–L4).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('approval_level', '>', 4)->update(['approval_level' => 4]);

        $labels = [
            'approve-voucher-level-1' => 'Approve voucher — L1 Finance Controller',
            'approve-voucher-level-2' => 'Approve voucher — L2 Finance Manager',
            'approve-voucher-level-3' => 'Approve voucher — L3 Finance Director',
            'approve-voucher-level-4' => 'Approve voucher — L4 General Director',
        ];
        foreach ($labels as $name => $displayName) {
            DB::table('permissions')->where('name', $name)->update(['display_name' => $displayName]);
        }

        $p5 = DB::table('permissions')->where('name', 'approve-voucher-level-5')->first();
        if ($p5) {
            DB::table('role_has_permissions')->where('permission_id', $p5->id)->delete();
            DB::table('model_has_permissions')->where('permission_id', $p5->id)->delete();
            DB::table('permissions')->where('id', $p5->id)->delete();
        }
    }

    public function down(): void
    {
        // Irreversible data cleanup; fresh installs use seeders without level 5.
    }
};
