<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_workflow_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->boolean('enable_approval_workflow')->default(true);
            $table->unsignedTinyInteger('approval_levels')->default(3);
            $table->decimal('approval_limit_level1', 15, 2)->default(1000);
            $table->decimal('approval_limit_level2', 15, 2)->default(10000);
            $table->decimal('approval_limit_level3', 15, 2)->default(50000);
            $table->boolean('require_dual_signature')->default(true);
            $table->decimal('dual_signature_threshold', 15, 2)->default(5000);
            $table->boolean('allow_self_approval')->default(false);
            $table->decimal('auto_approve_below', 15, 2)->default(100);
            $table->boolean('require_supporting_documents')->default(true);
            $table->timestamps();

            $table->unique('organization_id');
        });

        // Copy existing data from organizations (if columns exist)
        if (Schema::hasColumn('organizations', 'enable_approval_workflow')) {
            $orgs = DB::table('organizations')->get(['id', 'enable_approval_workflow', 'approval_levels',
                'approval_limit_level1', 'approval_limit_level2', 'approval_limit_level3',
                'require_dual_signature', 'dual_signature_threshold', 'allow_self_approval',
                'auto_approve_below', 'require_supporting_documents']);
            foreach ($orgs as $org) {
                DB::table('approval_workflow_settings')->insert([
                    'organization_id' => $org->id,
                    'enable_approval_workflow' => $org->enable_approval_workflow ?? true,
                    'approval_levels' => $org->approval_levels ?? 3,
                    'approval_limit_level1' => $org->approval_limit_level1 ?? 1000,
                    'approval_limit_level2' => $org->approval_limit_level2 ?? 10000,
                    'approval_limit_level3' => $org->approval_limit_level3 ?? 50000,
                    'require_dual_signature' => $org->require_dual_signature ?? true,
                    'dual_signature_threshold' => $org->dual_signature_threshold ?? 5000,
                    'allow_self_approval' => $org->allow_self_approval ?? false,
                    'auto_approve_below' => $org->auto_approve_below ?? 100,
                    'require_supporting_documents' => $org->require_supporting_documents ?? true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            Schema::table('organizations', function (Blueprint $table) {
                $table->dropColumn([
                    'enable_approval_workflow', 'approval_levels',
                    'approval_limit_level1', 'approval_limit_level2', 'approval_limit_level3',
                    'require_dual_signature', 'dual_signature_threshold',
                    'allow_self_approval', 'auto_approve_below', 'require_supporting_documents',
                ]);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('organizations', 'enable_approval_workflow')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->boolean('enable_approval_workflow')->default(true)->after('require_narration');
                $table->unsignedTinyInteger('approval_levels')->default(3)->after('enable_approval_workflow');
                $table->decimal('approval_limit_level1', 15, 2)->default(1000)->after('approval_levels');
                $table->decimal('approval_limit_level2', 15, 2)->default(10000)->after('approval_limit_level1');
                $table->decimal('approval_limit_level3', 15, 2)->default(50000)->after('approval_limit_level2');
                $table->boolean('require_dual_signature')->default(true)->after('approval_limit_level3');
                $table->decimal('dual_signature_threshold', 15, 2)->default(5000)->after('require_dual_signature');
                $table->boolean('allow_self_approval')->default(false)->after('dual_signature_threshold');
                $table->decimal('auto_approve_below', 15, 2)->default(100)->after('allow_self_approval');
                $table->boolean('require_supporting_documents')->default(true)->after('auto_approve_below');
            });
        }

        if (Schema::hasTable('approval_workflow_settings')) {
            $settings = DB::table('approval_workflow_settings')->get();
            foreach ($settings as $s) {
                DB::table('organizations')->where('id', $s->organization_id)->update([
                    'enable_approval_workflow' => $s->enable_approval_workflow,
                    'approval_levels' => $s->approval_levels,
                    'approval_limit_level1' => $s->approval_limit_level1,
                    'approval_limit_level2' => $s->approval_limit_level2,
                    'approval_limit_level3' => $s->approval_limit_level3,
                    'require_dual_signature' => $s->require_dual_signature,
                    'dual_signature_threshold' => $s->dual_signature_threshold,
                    'allow_self_approval' => $s->allow_self_approval,
                    'auto_approve_below' => $s->auto_approve_below,
                    'require_supporting_documents' => $s->require_supporting_documents,
                ]);
            }
            Schema::dropIfExists('approval_workflow_settings');
        }
    }
};
