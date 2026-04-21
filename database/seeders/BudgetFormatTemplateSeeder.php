<?php

namespace Database\Seeders;

use App\Models\BudgetFormatTemplate;
use App\Models\Donor;
use App\Models\Organization;
use Illuminate\Database\Seeder;

class BudgetFormatTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $organizations = Organization::all();
        if ($organizations->isEmpty()) {
            $this->command->warn('No organizations found. Run DatabaseSeeder first.');
            return;
        }

        foreach ($organizations as $org) {
            $this->seedTemplatesForOrganization($org->id);
        }

        $this->command->info('Budget format templates seeded: Legacy, UNICEF HER, UNFPA WHO.');
    }

    private function seedTemplatesForOrganization(int $orgId): void
    {
        $unicefDonor = Donor::where('organization_id', $orgId)->where('code', 'UNICEF')->first();
        $whoDonor = Donor::where('organization_id', $orgId)->where('code', 'WHO')->first();

        $templates = [
            [
                'name' => 'Legacy (Account + Q1–Q4)',
                'code' => 'legacy',
                'donor_id' => null,
                'structure_type' => 'account_based',
                'column_definition' => [
                    'code' => 'legacy',
                    'name' => 'Legacy (Account + Q1–Q4)',
                    'structure_type' => 'account_based',
                    'line_levels' => ['line'],
                    'columns' => [
                        ['key' => 'account_id', 'label' => 'Account', 'type' => 'account_picker', 'required' => true],
                        ['key' => 'description', 'label' => 'Description', 'type' => 'text'],
                        ['key' => 'q1_amount', 'label' => 'Q1', 'type' => 'currency'],
                        ['key' => 'q2_amount', 'label' => 'Q2', 'type' => 'currency'],
                        ['key' => 'q3_amount', 'label' => 'Q3', 'type' => 'currency'],
                        ['key' => 'q4_amount', 'label' => 'Q4', 'type' => 'currency'],
                        ['key' => 'annual_amount', 'label' => 'Annual', 'type' => 'currency', 'computed' => 'q1+q2+q3+q4'],
                    ],
                    'required_mappings' => ['account_id'],
                ],
            ],
            [
                'name' => 'UNICEF HER Project Budget',
                'code' => 'unicef_her',
                'donor_id' => $unicefDonor?->id,
                'structure_type' => 'activity_based',
                'column_definition' => [
                    'code' => 'unicef_her',
                    'name' => 'UNICEF HER Project Budget',
                    'structure_type' => 'activity_based',
                    'line_levels' => ['cp_output', 'pd_output', 'pd_activity'],
                    'sections' => [
                        ['code' => '1', 'label' => 'BPHS delivery', 'children' => ['1.1', '1.2', '1.3', '1.4', '1.5', '1.6', '1.7', '1.8']],
                        ['code' => '2', 'label' => 'EPHS delivery', 'children' => ['2.1', '2.2', '2.3', '2.4']],
                        ['code' => '3', 'label' => 'HIVA interventions', 'children' => ['3.1', '3.2', '3.3']],
                        ['code' => '4', 'label' => 'Nutrition services', 'children' => ['4.1', '4.2']],
                        ['code' => '5', 'label' => 'EPI', 'children' => ['5.1', '5.2']],
                        ['code' => '6', 'label' => 'Project Management', 'children' => ['6.1', '6.2']],
                        ['code' => 'EEPM', 'label' => 'Effective and efficient programme management', 'children' => ['EEPM.1', 'EEPM.2', 'EEPM.3']],
                    ],
                    'columns' => [
                        ['key' => 'section_code', 'label' => 'Section', 'type' => 'select', 'options_ref' => 'unicef_her_sections'],
                        ['key' => 'item_description', 'label' => 'Item Description', 'type' => 'text', 'required' => true],
                        ['key' => 'cso_contribution', 'label' => 'CSO Contribution (USD)', 'type' => 'currency'],
                        ['key' => 'unicef_contribution', 'label' => 'UNICEF Contribution (USD)', 'type' => 'currency'],
                        ['key' => 'total_amount', 'label' => 'Total (USD)', 'type' => 'currency', 'computed' => 'cso_contribution + unicef_contribution'],
                        ['key' => 'amend_amount', 'label' => 'Amendment', 'type' => 'currency'],
                        ['key' => 'remark', 'label' => 'Remark', 'type' => 'text'],
                        ['key' => 'unit_type', 'label' => 'Unit type', 'type' => 'text'],
                        ['key' => 'quantity', 'label' => 'Number of units', 'type' => 'number'],
                        ['key' => 'unit_cost', 'label' => 'Unit cost', 'type' => 'currency'],
                        ['key' => 'q1_amount', 'label' => 'Q1', 'type' => 'currency'],
                        ['key' => 'q2_amount', 'label' => 'Q2', 'type' => 'currency'],
                        ['key' => 'q3_amount', 'label' => 'Q3', 'type' => 'currency'],
                        ['key' => 'q4_amount', 'label' => 'Q4', 'type' => 'currency'],
                    ],
                    'capacity_strengthening_pct' => 7,
                    'required_mappings' => ['account_id'],
                ],
            ],
            [
                'name' => 'UNFPA Budget – WHO Standard Categories',
                'code' => 'unfpa_who',
                'donor_id' => $whoDonor?->id,
                'structure_type' => 'donor_code_based',
                'column_definition' => [
                    'code' => 'unfpa_who',
                    'name' => 'UNFPA Budget – WHO Standard Categories',
                    'structure_type' => 'donor_code_based',
                    'line_levels' => ['category', 'sub_category'],
                    'categories' => [
                        ['code' => '1', 'label' => 'Travel/meeting related expenses', 'children' => ['1.1', '1.2', '1.3', '1.4', '1.5', '1.6', '1.7', '1.8']],
                        ['code' => '2', 'label' => 'Infrastructure', 'children' => ['2.1', '2.2', '2.3', '2.4', '2.5']],
                        ['code' => '3', 'label' => 'Procurement of services', 'children' => ['3.1', '3.2', '3.3', '3.4', '3.5', '3.6']],
                        ['code' => '4', 'label' => 'Staff cost', 'children' => ['4.1', '4.2']],
                        ['code' => '5', 'label' => 'Procurement of health-related supplies and equipment', 'children' => ['5.1', '5.2', '5.3', '5.4', '5.5']],
                        ['code' => '6', 'label' => 'Procurement other than health related', 'children' => ['6.1', '6.2', '6.3', '6.4', '6.5', '6.6', '6.7']],
                        ['code' => '7', 'label' => 'Other costs', 'children' => ['7.1', '7.2', '7.3']],
                    ],
                    'columns' => [
                        ['key' => 'category_code', 'label' => 'Code', 'type' => 'select', 'options_ref' => 'who_categories'],
                        ['key' => 'budget_line_description', 'label' => 'Budget Line Description', 'type' => 'text', 'required' => true],
                        ['key' => 'unit_description', 'label' => 'Unit Description', 'type' => 'text'],
                        ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'number'],
                        ['key' => 'unit_cost', 'label' => 'Unit Cost', 'type' => 'currency'],
                        ['key' => 'duration_recurrence', 'label' => 'Duration/Recurrence', 'type' => 'text'],
                        ['key' => 'cost_pct', 'label' => '% Cost', 'type' => 'number'],
                        ['key' => 'total_cost', 'label' => 'Total Cost', 'type' => 'currency', 'computed' => 'quantity * unit_cost * (cost_pct/100)'],
                        ['key' => 'budget_narrative', 'label' => 'Budget Narrative', 'type' => 'textarea'],
                        ['key' => 'remarks', 'label' => 'Remarks', 'type' => 'text'],
                        ['key' => 'location', 'label' => 'Location of position', 'type' => 'text'],
                    ],
                    'psc_max_pct' => 7,
                    'required_mappings' => ['account_id'],
                ],
            ],
        ];

        foreach ($templates as $data) {
            BudgetFormatTemplate::updateOrCreate(
                [
                    'organization_id' => $orgId,
                    'code' => $data['code'],
                ],
                array_merge($data, [
                    'organization_id' => $orgId,
                    'is_active' => true,
                ])
            );
        }

        // Assign default format to UNICEF (unicef_her) and WHO (unfpa_who) donors
        $unicefHer = BudgetFormatTemplate::where('organization_id', $orgId)->where('code', 'unicef_her')->first();
        $unfpaWho = BudgetFormatTemplate::where('organization_id', $orgId)->where('code', 'unfpa_who')->first();

        if ($unicefDonor && $unicefHer) {
            Donor::where('id', $unicefDonor->id)->update(['default_budget_format_id' => $unicefHer->id]);
        }
        if ($whoDonor && $unfpaWho) {
            Donor::where('id', $whoDonor->id)->update(['default_budget_format_id' => $unfpaWho->id]);
        }
    }
}
