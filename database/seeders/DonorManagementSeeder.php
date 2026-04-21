<?php

namespace Database\Seeders;

use App\Models\Donor;
use App\Models\Grant;
use App\Models\Donation;
use App\Models\Pledge;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;

class DonorManagementSeeder extends Seeder
{
    /**
     * Seed draft/sample data for Donor Management sub-module:
     * donors (with contact details), grants, donations, pledges.
     */
    public function run(): void
    {
        $organization = Organization::first();
        $user = User::first();
        if (!$organization || !$user) {
            $this->command->warn('Run DatabaseSeeder first (organization and user required).');
            return;
        }

        $orgId = $organization->id;
        $createdBy = $user->id;

        // Ensure we have donors (DatabaseSeeder may have created UNICEF, WHO, EU, USAID)
        $donors = $this->ensureDonors($orgId);
        $unicef = $donors['UNICEF'] ?? null;
        $who = $donors['WHO'] ?? null;
        $eu = $donors['EU'] ?? null;
        $usaid = $donors['USAID'] ?? null;
        $gates = $donors['GATES'] ?? null;
        $acme = $donors['ACME'] ?? null;

        // Create grants (draft and active)
        $grants = [];
        if ($unicef) {
            $grants['UNICEF-EDU-2024'] = Grant::firstOrCreate(
                ['organization_id' => $orgId, 'grant_code' => 'UNICEF-EDU-2024'],
                [
                    'donor_id' => $unicef->id,
                    'grant_name' => 'Education in Emergencies - Kabul & Balkh',
                    'description' => 'Support for non-formal education and teacher training.',
                    'start_date' => '2024-01-01',
                    'end_date' => '2024-12-31',
                    'total_amount' => 450000.00,
                    'currency' => 'USD',
                    'status' => 'active',
                    'contract_reference' => 'CONTRACT-UNICEF-2024-001',
                    'contract_date' => '2023-11-15',
                ]
            );
        }
        if ($who) {
            $grants['WHO-HEALTH-2024'] = Grant::firstOrCreate(
                ['organization_id' => $orgId, 'grant_code' => 'WHO-HEALTH-2024'],
                [
                    'donor_id' => $who->id,
                    'grant_name' => 'Primary Healthcare Strengthening',
                    'description' => 'Medical supplies and facility support in target provinces.',
                    'start_date' => '2024-03-01',
                    'end_date' => '2025-02-28',
                    'total_amount' => 280000.00,
                    'currency' => 'USD',
                    'status' => 'active',
                    'contract_reference' => 'WHO-MOU-2024-089',
                    'contract_date' => '2024-01-10',
                ]
            );
        }
        if ($eu) {
            $grants['EU-LIVELIHOOD-2024'] = Grant::firstOrCreate(
                ['organization_id' => $orgId, 'grant_code' => 'EU-LIVELIHOOD-2024'],
                [
                    'donor_id' => $eu->id,
                    'grant_name' => 'Livelihoods and Resilience',
                    'description' => 'Vocational training and small business grants.',
                    'start_date' => '2024-06-01',
                    'end_date' => '2025-05-31',
                    'total_amount' => 520000.00,
                    'currency' => 'EUR',
                    'status' => 'draft',
                    'contract_reference' => null,
                    'contract_date' => null,
                ]
            );
        }

        $grantUnicef = $grants['UNICEF-EDU-2024'] ?? null;
        $grantWho = $grants['WHO-HEALTH-2024'] ?? null;

        // Create donations (draft/sample)
        $donationNumber = 1;
        $donationEntries = [];
        if ($unicef) {
            $donationEntries[] = [
                'donor_id' => $unicef->id,
                'grant_id' => $grantUnicef?->id,
                'donation_date' => '2024-02-15',
                'donation_type' => 'grant_disbursement',
                'description' => 'Q1 disbursement - Education in Emergencies',
                'currency' => 'USD',
                'amount' => 112500.00,
                'base_currency_amount' => 112500.00,
                'receipt_method' => 'bank_transfer',
                'bank_reference' => 'UNICEF-TRF-2024-001',
                'status' => 'received',
            ];
            $donationEntries[] = [
                'donor_id' => $unicef->id,
                'grant_id' => $grantUnicef?->id,
                'donation_date' => '2024-05-20',
                'donation_type' => 'grant_disbursement',
                'description' => 'Q2 disbursement - Education program',
                'currency' => 'USD',
                'amount' => 112500.00,
                'base_currency_amount' => 112500.00,
                'receipt_method' => 'wire_transfer',
                'bank_reference' => 'UNICEF-WIRE-2024-002',
                'status' => 'received',
            ];
        }
        if ($who) {
            $donationEntries[] = [
                'donor_id' => $who->id,
                'grant_id' => $grantWho?->id,
                'donation_date' => '2024-04-01',
                'donation_type' => 'grant_disbursement',
                'description' => 'First tranche - Health project',
                'currency' => 'USD',
                'amount' => 70000.00,
                'base_currency_amount' => 70000.00,
                'receipt_method' => 'bank_transfer',
                'status' => 'received',
            ];
        }
        if ($gates) {
            $donationEntries[] = [
                'donor_id' => $gates->id,
                'grant_id' => null,
                'donation_date' => '2024-03-10',
                'donation_type' => 'cash',
                'description' => 'General unrestricted donation',
                'currency' => 'USD',
                'amount' => 25000.00,
                'base_currency_amount' => 25000.00,
                'receipt_method' => 'bank_transfer',
                'status' => 'pending',
            ];
        }
        if ($acme) {
            $donationEntries[] = [
                'donor_id' => $acme->id,
                'grant_id' => null,
                'donation_date' => '2024-01-20',
                'donation_type' => 'cash',
                'description' => 'Corporate gift - office equipment',
                'currency' => 'USD',
                'amount' => 5000.00,
                'base_currency_amount' => 5000.00,
                'receipt_method' => 'check',
                'check_number' => 'CHK-1001',
                'status' => 'acknowledged',
            ];
        }

        foreach ($donationEntries as $entry) {
            $num = str_pad((string) $donationNumber++, 5, '0', STR_PAD_LEFT);
            Donation::firstOrCreate(
                [
                    'organization_id' => $orgId,
                    'donation_number' => 'DON-' . date('Y') . '-' . $num,
                ],
                array_merge($entry, [
                    'organization_id' => $orgId,
                    'fund_id' => null,
                    'exchange_rate' => 1,
                    'restriction_type' => 'unrestricted',
                    'created_by' => $createdBy,
                ])
            );
        }

        // Create pledges (draft/sample)
        $pledgeNumber = 1;
        $pledgeEntries = [];
        if ($eu) {
            $pledgeEntries[] = [
                'donor_id' => $eu->id,
                'grant_id' => $grants['EU-LIVELIHOOD-2024']?->id,
                'pledge_date' => '2024-02-01',
                'description' => 'Annual pledge - Livelihoods program 2024',
                'currency' => 'EUR',
                'pledged_amount' => 130000.00,
                'received_amount' => 65000.00,
                'outstanding_amount' => 65000.00,
                'expected_fulfillment_date' => '2024-12-31',
                'payment_schedule' => 'quarterly',
                'status' => 'partially_fulfilled',
            ];
        }
        if ($usaid) {
            $pledgeEntries[] = [
                'donor_id' => $usaid->id,
                'grant_id' => null,
                'pledge_date' => '2024-04-15',
                'description' => 'Pledge for WASH project - pending agreement',
                'currency' => 'USD',
                'pledged_amount' => 200000.00,
                'received_amount' => 0,
                'outstanding_amount' => 200000.00,
                'expected_fulfillment_date' => '2025-06-30',
                'payment_schedule' => 'one_time',
                'status' => 'active',
            ];
        }
        if ($gates) {
            $pledgeEntries[] = [
                'donor_id' => $gates->id,
                'grant_id' => null,
                'pledge_date' => '2024-01-10',
                'description' => 'Multi-year pledge - health innovation',
                'currency' => 'USD',
                'pledged_amount' => 100000.00,
                'received_amount' => 25000.00,
                'outstanding_amount' => 75000.00,
                'expected_fulfillment_date' => '2024-12-31',
                'payment_schedule' => 'quarterly',
                'status' => 'partially_fulfilled',
            ];
        }

        foreach ($pledgeEntries as $entry) {
            $num = str_pad((string) $pledgeNumber++, 5, '0', STR_PAD_LEFT);
            Pledge::firstOrCreate(
                [
                    'organization_id' => $orgId,
                    'pledge_number' => 'PLD-' . date('Y') . '-' . $num,
                ],
                array_merge($entry, [
                    'organization_id' => $orgId,
                    'created_by' => $createdBy,
                ])
            );
        }

        $this->command->info('Donor Management draft data seeded: donors (updated/created), grants, donations, pledges.');
    }

    private function ensureDonors(int $orgId): array
    {
        $byCode = [];
        $existing = Donor::where('organization_id', $orgId)->get()->keyBy('code');
        foreach ($existing as $code => $donor) {
            $byCode[$code] = $donor;
        }

        $draftDonors = [
            [
                'code' => 'UNICEF',
                'name' => 'United Nations Children\'s Fund',
                'short_name' => 'UNICEF',
                'donor_type' => 'multilateral',
                'contact_person' => 'Jane Smith',
                'email' => 'partnerships@unicef.org',
                'phone' => '+1 212 326 7000',
                'address' => '3 United Nations Plaza, New York, NY 10017',
                'country' => 'International',
                'website' => 'https://www.unicef.org',
                'notes' => 'Primary partner for education programs.',
                'reporting_currency' => 'USD',
                'reporting_frequency' => 'Quarterly',
                'is_active' => true,
            ],
            [
                'code' => 'WHO',
                'name' => 'World Health Organization',
                'short_name' => 'WHO',
                'donor_type' => 'multilateral',
                'contact_person' => 'Dr. Ahmed Hassan',
                'email' => 'countryoffice@who.int',
                'phone' => '+93 70 012 3456',
                'address' => 'Kabul, Afghanistan',
                'country' => 'Afghanistan',
                'website' => 'https://www.who.int',
                'notes' => 'Health sector programs.',
                'reporting_currency' => 'USD',
                'reporting_frequency' => 'Quarterly',
                'is_active' => true,
            ],
            [
                'code' => 'EU',
                'name' => 'European Union',
                'short_name' => 'EU',
                'donor_type' => 'multilateral',
                'contact_person' => 'Maria Lopez',
                'email' => 'delegation-afghanistan@eeas.europa.eu',
                'phone' => '+32 2 299 11 11',
                'address' => 'Brussels, Belgium',
                'country' => 'Belgium',
                'website' => 'https://ec.europa.eu',
                'notes' => 'Livelihoods and resilience.',
                'reporting_currency' => 'EUR',
                'reporting_frequency' => 'Semi-annual',
                'is_active' => true,
            ],
            [
                'code' => 'USAID',
                'name' => 'United States Agency for International Development',
                'short_name' => 'USAID',
                'donor_type' => 'bilateral',
                'contact_person' => 'John Davis',
                'email' => 'info@usaid.gov',
                'phone' => '+1 202 712 4810',
                'address' => 'Washington, D.C.',
                'country' => 'United States',
                'website' => 'https://www.usaid.gov',
                'notes' => 'WASH and governance.',
                'reporting_currency' => 'USD',
                'reporting_frequency' => 'Quarterly',
                'is_active' => true,
            ],
            [
                'code' => 'GATES',
                'name' => 'Bill & Melinda Gates Foundation',
                'short_name' => 'Gates Foundation',
                'donor_type' => 'foundation',
                'contact_person' => 'Sarah Chen',
                'email' => 'grants@gatesfoundation.org',
                'phone' => '+1 206 709 3100',
                'address' => 'Seattle, WA, USA',
                'country' => 'United States',
                'website' => 'https://www.gatesfoundation.org',
                'notes' => 'Health and development grants.',
                'reporting_currency' => 'USD',
                'reporting_frequency' => 'Annual',
                'is_active' => true,
            ],
            [
                'code' => 'ACME',
                'name' => 'ACME Corporation',
                'short_name' => 'ACME',
                'donor_type' => 'corporate',
                'contact_person' => 'Ali Khan',
                'email' => 'csr@acme.example.com',
                'phone' => '+93 79 123 4567',
                'address' => 'Kabul, Afghanistan',
                'country' => 'Afghanistan',
                'website' => null,
                'notes' => 'In-kind and cash donations.',
                'reporting_currency' => 'USD',
                'reporting_frequency' => null,
                'is_active' => true,
            ],
        ];

        foreach ($draftDonors as $data) {
            $code = $data['code'];
            if (isset($byCode[$code])) {
                $byCode[$code]->update(array_merge($data, ['organization_id' => $orgId]));
            } else {
                $byCode[$code] = Donor::create(array_merge($data, ['organization_id' => $orgId]));
            }
        }

        return $byCode;
    }
}
