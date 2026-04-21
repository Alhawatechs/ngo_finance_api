<?php

namespace App\Services;

use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Voucher;
use App\Services\OfficeContext;
use Carbon\Carbon;

/**
 * Generates voucher numbers from the Coding Block for Voucher Number rules:
 * Project Code (2) + Province Code (2) + Month Code (1) + Year Code (2) + Location Code (1) + Transaction Code (3 e.g. A01).
 * Transaction series per month starts from A01 for each (project, province, year, month, location).
 * Organizations can use the suggested default (built-in provinces/locations/month codes) or define their own via coding_block_config.
 */
class CodingBlockVoucherNumberService
{
    /** Month code: January=A, February=B, ..., December=L (suggested default) */
    private const MONTH_CODES = [
        1 => 'A', 2 => 'B', 3 => 'C', 4 => 'D', 5 => 'E', 6 => 'F',
        7 => 'G', 8 => 'H', 9 => 'I', 10 => 'J', 11 => 'K', 12 => 'L',
    ];

    /** Suggested default provinces (Province Name => Province Code 2 digits) */
    private static function getProvincesDefault(): array
    {
        return [
            ['name' => 'Kabul', 'code' => '01'],
            ['name' => 'Nooristan', 'code' => '02'],
            ['name' => 'Kunar', 'code' => '03'],
            ['name' => 'Nangarhar', 'code' => '04'],
            ['name' => 'Laghman', 'code' => '05'],
            ['name' => 'Kapisa', 'code' => '06'],
            ['name' => 'Pajshir', 'code' => '07'],
            ['name' => 'Parwan', 'code' => '08'],
            ['name' => 'Wardak', 'code' => '09'],
            ['name' => 'Logar', 'code' => '10'],
            ['name' => 'Ghazni', 'code' => '11'],
            ['name' => 'Khost', 'code' => '12'],
            ['name' => 'Paktia', 'code' => '13'],
            ['name' => 'Paktika', 'code' => '14'],
            ['name' => 'Zabul', 'code' => '15'],
            ['name' => 'Urozgan', 'code' => '16'],
            ['name' => 'Sar-e-Pul', 'code' => '17'],
            ['name' => 'Bamyan', 'code' => '18'],
            ['name' => 'Kandahar', 'code' => '19'],
            ['name' => 'Daikundi', 'code' => '20'],
            ['name' => 'Nimroz', 'code' => '21'],
            ['name' => 'Helmand', 'code' => '22'],
            ['name' => 'Farah', 'code' => '23'],
            ['name' => 'Herat', 'code' => '24'],
            ['name' => 'Ghor', 'code' => '25'],
            ['name' => 'Badhis', 'code' => '26'],
            ['name' => 'Faryab', 'code' => '27'],
            ['name' => 'Jawzjan', 'code' => '28'],
            ['name' => 'Balkh', 'code' => '29'],
            ['name' => 'Samangan', 'code' => '30'],
            ['name' => 'Kunduz', 'code' => '31'],
            ['name' => 'Takhar', 'code' => '32'],
            ['name' => 'Badakhshan', 'code' => '33'],
            ['name' => 'Baghlan', 'code' => '34'],
        ];
    }

    /**
     * Resolve config for a given location from org coding_block_config.
     * Supports by_location[code] (per-location) or legacy top-level provinces/locations/month_codes.
     */
    private static function getConfigForLocation(?Organization $org, ?string $locationCode): ?array
    {
        if (! $org || ! is_array($org->coding_block_config ?? null)) {
            return null;
        }
        $config = $org->coding_block_config;
        if (! empty($config['by_location']) && is_array($config['by_location']) && $locationCode !== null && isset($config['by_location'][$locationCode])) {
            return $config['by_location'][$locationCode];
        }
        if (! empty($config['provinces']) || ! empty($config['locations']) || ! empty($config['month_codes'])) {
            return $config;
        }
        return null;
    }

    /** Province list: from organization coding_block_config (by_location or legacy) or suggested default. */
    public static function getProvinces(?Organization $org = null, ?string $locationCode = null): array
    {
        $config = self::getConfigForLocation($org, $locationCode);
        $provinces = $config['provinces'] ?? null;
        return is_array($provinces) && ! empty($provinces) ? $provinces : self::getProvincesDefault();
    }

    /** Suggested default location codes */
    private static function getLocationsDefault(): array
    {
        return [
            ['name' => 'Main Office', 'code' => '1'],
            ['name' => 'Sub-Office', 'code' => '2'],
            ['name' => 'Health Facilities', 'code' => '3'],
        ];
    }

    /** Location list: from organization coding_block_config (by_location or legacy) or suggested default. */
    public static function getLocations(?Organization $org = null, ?string $locationCode = null): array
    {
        $config = self::getConfigForLocation($org, $locationCode);
        $locations = $config['locations'] ?? null;
        return is_array($locations) && ! empty($locations) ? $locations : self::getLocationsDefault();
    }

    /** Month codes for generation: from organization coding_block_config (by_location or legacy) or suggested default. Returns array 1=>'A', 2=>'B', ... */
    public static function getMonthCodes(?Organization $org = null, ?string $locationCode = null): array
    {
        $config = self::getConfigForLocation($org, $locationCode);
        $monthCodes = $config['month_codes'] ?? null;
        if (is_array($monthCodes) && ! empty($monthCodes)) {
            $out = [];
            foreach ($monthCodes as $k => $v) {
                $out[(int) $k] = (string) $v;
            }
            return $out ?: self::MONTH_CODES;
        }
        return self::MONTH_CODES;
    }

    /**
     * Full Coding Block specification for voucher number (used by API and documentation).
     * Uses organization config for the given location when set.
     */
    public static function getFormatSpec(?Organization $org = null, ?string $locationCode = null): array
    {
        $monthCodes = self::getMonthCodes($org, $locationCode);
        return [
            'description' => 'Voucher numbers follow the Coding Block: Project Code (2) + Province Code (2) + Month Code (1) + Year Code (2) + Location Code (1) + Transaction Code (3). Used for both payment/receipt vouchers and journal entries when project and location are set.',
            'pattern' => 'Project(2) + Province(2) + Month(1) + Year(2) + Location(1) + Transaction(3)',
            'example' => '0A01A261A01',
            'components' => [
                ['name' => 'Project Code', 'length' => 2, 'description' => 'From project (e.g. 0A, 0B, AA)', 'example' => '0A'],
                ['name' => 'Province Code', 'length' => 2, 'description' => 'Province code from your list', 'example' => '01'],
                ['name' => 'Month Code', 'length' => 1, 'description' => 'January=A … December=L (or your codes)', 'example' => 'A'],
                ['name' => 'Year Code', 'length' => 2, 'description' => 'Last two digits of year', 'example' => '26'],
                ['name' => 'Location Code', 'length' => 1, 'description' => 'From your location list', 'example' => '1'],
                ['name' => 'Transaction Code', 'length' => 3, 'description' => 'Sequence per month: A01, A02, …', 'example' => 'A01'],
            ],
            'month_codes' => $monthCodes,
        ];
    }

    /** Suggested default coding block config (provinces, locations, month_codes) for "Use suggested" in settings. */
    public static function getSuggestedConfig(): array
    {
        return [
            'provinces' => self::getProvincesDefault(),
            'locations' => self::getLocationsDefault(),
            'month_codes' => self::MONTH_CODES,
        ];
    }

    /** Location options for settings UI: main office (1) and sub offices (2, 3, ...). */
    public static function getLocationOptions(): array
    {
        $locations = self::getLocationsDefault();
        return array_map(fn ($l) => ['code' => $l['code'], 'name' => $l['name']], $locations);
    }

    /**
     * Sample voucher numbers by location for display in settings.
     * Uses project code 0A, first province (e.g. 01 Kabul), current month/year, location code, and A01.
     * Shows how the same project/province produces different voucher numbers per location (e.g. 0A01A261A01 vs 0A01A262A01).
     */
    public static function getSampleVoucherNumbersByLocation(?Organization $org = null): array
    {
        $locationOptions = self::getLocationOptions();
        $now = Carbon::now();
        $yearCode = $now->format('y');
        $projectCode = '0A';
        $samples = [];
        foreach ($locationOptions as $loc) {
            $code = $loc['code'];
            $provinces = self::getProvinces($org, $code);
            $provinceCode = isset($provinces[0]['code']) ? $provinces[0]['code'] : '01';
            $monthCodes = self::getMonthCodes($org, $code);
            $monthCode = $monthCodes[(int) $now->format('n')] ?? 'A';
            $samples[$code] = $projectCode . $provinceCode . $monthCode . $yearCode . $code . 'A01';
        }
        return $samples;
    }

    /**
     * Get next voucher number in coding block format.
     * Format: ProjectCode(2) + ProvinceCode(2) + MonthCode(1) + YearCode(2) + LocationCode(1) + TransactionCode(3 e.g. A01).
     * Uses organization's coding_block_config for month codes when provided.
     *
     * @param int    $organizationId
     * @param int    $projectId
     * @param string $provinceCode   Two digits e.g. 01, 24
     * @param string $voucherDate    Y-m-d
     * @param string $locationCode   1, 2, or 3
     * @param Organization|null $org Optional; when set, month codes (and future custom rules) come from org config
     */
    public function getNextNumber(
        int $organizationId,
        int $projectId,
        string $provinceCode,
        string $voucherDate,
        string $locationCode,
        ?Organization $org = null
    ): string {
        $connection = OfficeContext::connection();
        $project = Project::on($connection)
            ->where('organization_id', $organizationId)
            ->find($projectId);

        if (! $project) {
            throw new \InvalidArgumentException('Project not found.');
        }

        $monthCodes = self::getMonthCodes($org, $locationCode);
        $projectCode = $this->normalizeProjectCode($project->project_code ?? '');
        $date = Carbon::parse($voucherDate);
        $monthCode = $monthCodes[(int) $date->format('n')] ?? 'A';
        $yearCode = $date->format('y');

        $nextSeq = $this->getNextSequence($organizationId, $projectId, $provinceCode, $voucherDate, $locationCode);
        $transCode = 'A' . str_pad((string) $nextSeq, 2, '0', STR_PAD_LEFT);

        return $projectCode . $provinceCode . $monthCode . $yearCode . $locationCode . $transCode;
    }

    /**
     * Get next voucher number in coding block format for a journal entry.
     * Uses the journal's project, province_code, office (for location code), and entry date.
     * Sequence is based on count of journal entries in the same (project, province, month, location).
     * Uses organization's coding_block_config for month codes when provided.
     */
    public function getNextNumberForJournalEntry(int $organizationId, Journal $journal, string $entryDate, ?Organization $org = null): string
    {
        $journal->loadMissing(['project', 'office']);
        if (! $journal->project_id || ! $journal->project) {
            throw new \InvalidArgumentException('Journal must have a project to generate voucher number.');
        }
        if (empty($journal->province_code)) {
            throw new \InvalidArgumentException('Journal must have province_code to generate voucher number.');
        }
        if (! $journal->office_id || ! $journal->office) {
            throw new \InvalidArgumentException('Journal must have an office to generate voucher number.');
        }

        $locationCode = $journal->location_code;
        $monthCodes = self::getMonthCodes($org, $locationCode);
        $projectCode = $this->normalizeProjectCode($journal->project->project_code ?? '');
        $provinceCode = $journal->province_code;
        $date = Carbon::parse($entryDate);
        $monthCode = $monthCodes[(int) $date->format('n')] ?? 'A';
        $yearCode = $date->format('y');

        $nextSeq = $this->getNextSequenceForJournalEntry($organizationId, $journal, $entryDate);
        $transCode = 'A' . str_pad((string) $nextSeq, 2, '0', STR_PAD_LEFT);

        return $projectCode . $provinceCode . $monthCode . $yearCode . $locationCode . $transCode;
    }

    /**
     * Next transaction number in the month for journal entries (same project, province, location).
     */
    private function getNextSequenceForJournalEntry(int $organizationId, Journal $journal, string $entryDate): int
    {
        $date = Carbon::parse($entryDate);
        $startOfMonth = $date->copy()->startOfMonth()->format('Y-m-d');
        $endOfMonth = $date->copy()->endOfMonth()->format('Y-m-d');

        $isHeadOffice = $journal->office && $journal->office->is_head_office;
        $journalIds = Journal::where('organization_id', $organizationId)
            ->where('project_id', $journal->project_id)
            ->where('province_code', $journal->province_code)
            ->whereHas('office', fn ($q) => $q->where('is_head_office', $isHeadOffice))
            ->pluck('id');

        if ($journalIds->isEmpty()) {
            return 1;
        }

        $connection = OfficeContext::connection();
        $count = JournalEntry::on($connection)
            ->whereIn('journal_id', $journalIds)
            ->whereBetween('entry_date', [$startOfMonth, $endOfMonth])
            ->count();

        return $count + 1;
    }

    /**
     * Normalize project code to 2 characters for coding block (e.g. 0A, 0B).
     */
    private function normalizeProjectCode(string $code): string
    {
        $code = trim($code);
        if (strlen($code) >= 2) {
            return strtoupper(substr($code, 0, 2));
        }
        return str_pad(strtoupper($code), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Next transaction number in the month for (project, province, year, month, location). Series starts at A01 each month.
     */
    private function getNextSequence(
        int $organizationId,
        int $projectId,
        string $provinceCode,
        string $voucherDate,
        string $locationCode
    ): int {
        $date = Carbon::parse($voucherDate);
        $startOfMonth = $date->copy()->startOfMonth()->format('Y-m-d');
        $endOfMonth = $date->copy()->endOfMonth()->format('Y-m-d');

        $connection = OfficeContext::connection();
        $count = Voucher::on($connection)
            ->toBase()
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('province_code', $provinceCode)
            ->where('location_code', $locationCode)
            ->whereBetween('voucher_date', [$startOfMonth, $endOfMonth])
            ->count();

        return $count + 1;
    }

    public static function getMonthCode(string $date, ?Organization $org = null): string
    {
        $month = (int) Carbon::parse($date)->format('n');
        $codes = self::getMonthCodes($org);
        return $codes[$month] ?? 'A';
    }

    public static function getYearCode(string $date): string
    {
        return Carbon::parse($date)->format('y');
    }
}
