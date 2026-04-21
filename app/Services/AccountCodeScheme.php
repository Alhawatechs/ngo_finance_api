<?php

namespace App\Services;

/**
 * Dotted hierarchical account codes:
 * L1: 1–5 (Income, Expenses, Assets, Liabilities, Fund Balance)
 * L2: two or more digits, no dot — first digit = L1 bucket (e.g. 11, 12… under 1; 21… under 2)
 * L3: L2 + '.' + segment (e.g. 11.1)
 * L4: L3 + '.' + segment (e.g. 11.1.1)
 */
final class AccountCodeScheme
{
    public const L1_ORDER = ['revenue' => '1', 'expense' => '2', 'asset' => '3', 'liability' => '4', 'equity' => '5'];

    public const L1_DIGIT_TO_TYPE = [
        '1' => 'revenue',
        '2' => 'expense',
        '3' => 'asset',
        '4' => 'liability',
        '5' => 'equity',
    ];

    /** Lexicographic-safe padded key for SQL ORDER BY */
    public static function sortKey(string $code): string
    {
        $segments = self::segments($code);
        $out = '';
        foreach ($segments as $seg) {
            if (! ctype_digit($seg)) {
                return $code;
            }
            $out .= str_pad($seg, 8, '0', STR_PAD_LEFT);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function segments(string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            return [];
        }
        if (! str_contains($code, '.')) {
            return [$code];
        }

        return explode('.', $code);
    }

    public static function levelFromCode(string $code): ?int
    {
        $code = trim($code);
        if ($code === '' || ! self::isWellFormed($code)) {
            return null;
        }
        if (! str_contains($code, '.')) {
            return strlen($code) === 1 ? 1 : 2;
        }
        $dots = substr_count($code, '.');

        return $dots === 1 ? 3 : ($dots === 2 ? 4 : null);
    }

    public static function isWellFormed(string $code): bool
    {
        $code = trim($code);
        if ($code === '' || strlen($code) > 20) {
            return false;
        }
        if (substr_count($code, '.') > 2) {
            return false;
        }
        if (! str_contains($code, '.')) {
            if (strlen($code) === 1) {
                return (bool) preg_match('/^[1-5]$/', $code);
            }
            // Legacy NGO numeric blocks (e.g. 10000) are not valid dotted scheme codes
            if (strlen($code) >= 5 && ctype_digit($code)) {
                return false;
            }

            return (bool) preg_match('/^[1-5]\d+$/', $code);
        }

        return (bool) preg_match('/^[1-5]\d+\.\d+(?:\.\d+)?$/', $code);
    }

    public static function isValidChildCode(string $child, string $parentCode): bool
    {
        if (! self::isWellFormed($child) || ! self::isWellFormed($parentCode)) {
            return false;
        }
        $cl = self::levelFromCode($child);
        $pl = self::levelFromCode($parentCode);
        if ($cl === null || $pl === null || $cl !== $pl + 1) {
            return false;
        }
        if ($pl === 1) {
            return str_starts_with($child, $parentCode)
                && strlen($child) > strlen($parentCode)
                && ! str_contains($child, '.')
                && (bool) preg_match('/^[1-5]\d+$/', $child);
        }

        return str_starts_with($child, $parentCode.'.');
    }

    /**
     * Natural order: 1 < 2 < 11 < 11.1 < 11.1.1 < 11.2 < 11.10
     */
    public static function compare(string $a, string $b): int
    {
        $sa = self::segments($a);
        $sb = self::segments($b);
        $n = max(count($sa), count($sb));
        for ($i = 0; $i < $n; $i++) {
            $ea = $sa[$i] ?? null;
            $eb = $sb[$i] ?? null;
            if ($ea === null) {
                return -1;
            }
            if ($eb === null) {
                return 1;
            }
            $ia = (int) $ea;
            $ib = (int) $eb;
            if ($ia !== $ib) {
                return $ia <=> $ib;
            }
        }

        return 0;
    }

    /**
     * @param  list<string>  $siblingCodes  Same parent
     * @param  array<string, bool>  $usedCodes  Map code => true (all org codes)
     */
    public static function nextCode(?string $parentCode, int $parentLevel, array $siblingCodes, array $usedCodes): ?string
    {
        if ($parentCode === null) {
            for ($d = 1; $d <= 5; $d++) {
                $c = (string) $d;
                if (! isset($usedCodes[$c])) {
                    return $c;
                }
            }

            return null;
        }

        if ($parentLevel === 1) {
            $bucket = $parentCode;
            $maxSuffix = 0;
            foreach ($siblingCodes as $sc) {
                if (! str_starts_with($sc, $bucket) || str_contains($sc, '.')) {
                    continue;
                }
                $suffix = substr($sc, strlen($bucket));
                if ($suffix !== '' && ctype_digit($suffix)) {
                    $maxSuffix = max($maxSuffix, (int) $suffix);
                }
            }
            for ($n = $maxSuffix + 1; $n <= 999; $n++) {
                $candidate = $bucket.$n;
                if (strlen($candidate) <= 20 && ! isset($usedCodes[$candidate])) {
                    return $candidate;
                }
            }

            return null;
        }

        if ($parentLevel === 2 || $parentLevel === 3) {
            $maxSeg = 0;
            $prefix = $parentCode.'.';
            $len = strlen($prefix);
            foreach ($siblingCodes as $sc) {
                if (! str_starts_with($sc, $prefix)) {
                    continue;
                }
                $rest = substr($sc, $len);
                if ($rest === '' || str_contains($rest, '.')) {
                    continue;
                }
                $maxSeg = max($maxSeg, (int) $rest);
            }
            for ($n = $maxSeg + 1; $n <= 999999; $n++) {
                $candidate = $parentCode.'.'.$n;
                if (strlen($candidate) <= 20 && ! isset($usedCodes[$candidate])) {
                    return $candidate;
                }
            }

            return null;
        }

        return null;
    }

    public static function validateMatchesLevel(string $code, int $level): bool
    {
        $l = self::levelFromCode($code);

        return $l !== null && $l === $level;
    }

    /**
     * Immediate parent account code for a well-formed code, or null for top-level (L1).
     */
    public static function parentCodeForCode(string $code): ?string
    {
        $code = trim($code);
        if ($code === '' || ! self::isWellFormed($code)) {
            return null;
        }
        $level = self::levelFromCode($code);
        if ($level === null || $level <= 1) {
            return null;
        }
        if ($level === 2) {
            return substr($code, 0, 1);
        }
        $lastDot = strrpos($code, '.');
        if ($lastDot === false) {
            return null;
        }

        return substr($code, 0, $lastDot);
    }
}
