<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Organization Settings
    |--------------------------------------------------------------------------
    */
    'organization' => [
        'name' => 'AADA - Afghan Aid Development Agency',
        'short_name' => 'AADA',
        'country' => 'Afghanistan',
        'default_currency' => 'AFN',
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency Settings
    |--------------------------------------------------------------------------
    */
    'currencies' => [
        'default' => 'AFN',
        'supported' => ['USD', 'EUR', 'GBP', 'AFN', 'PKR', 'INR', 'AED', 'SAR', 'CHF', 'JPY', 'CAD', 'AUD'],
        'decimal_places' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fiscal Year Settings
    |--------------------------------------------------------------------------
    */
    'fiscal_year' => [
        'start_month' => 1, // January
        'start_day' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Approval Workflow Settings
    |--------------------------------------------------------------------------
    */
    'approval' => [
        /** Maximum voucher approval layers (L1–L4). Amount thresholds determine how many apply per voucher. */
        'levels' => 4,
        'roles' => [
            1 => 'Finance Controller',
            2 => 'Finance Manager',
            3 => 'Finance Director',
            4 => 'General Director',
        ],
        'thresholds' => [
            // Amount thresholds in base currency (USD): required approval level = highest tier where amount >= threshold
            1 => 0,
            2 => 1000,
            3 => 5000,
            4 => 25000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Chart of Accounts Settings
    |--------------------------------------------------------------------------
    */
    'chart_of_accounts' => [
        'levels' => 4,  // Category → Subcategory → General Ledger → Account (direct GL linkage)
        'types' => [
            'asset' => 'Assets',
            'liability' => 'Liabilities',
            'equity' => 'Equity',
            'revenue' => 'Revenue',
            'expense' => 'Expenses',
        ],
        'fund_types' => [
            'unrestricted' => 'Unrestricted',
            'restricted' => 'Restricted',
            'temporarily_restricted' => 'Temporarily Restricted',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Regional Offices
    |--------------------------------------------------------------------------
    */
    'offices' => [
        'kabul' => [
            'name' => 'Kabul Head Office',
            'code' => 'KBL',
            'is_head_office' => true,
        ],
        'mazar' => [
            'name' => 'Mazar-i-Sharif Regional Office',
            'code' => 'MZR',
            'is_head_office' => false,
        ],
        'herat' => [
            'name' => 'Herat Regional Office',
            'code' => 'HRT',
            'is_head_office' => false,
        ],
        'kandahar' => [
            'name' => 'Kandahar Regional Office',
            'code' => 'KDH',
            'is_head_office' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Voucher Settings
    |--------------------------------------------------------------------------
    */
    'voucher' => [
        'types' => [
            'payment' => 'Payment Voucher',
            'receipt' => 'Receipt Voucher',
            'journal' => 'Journal Voucher',
            'contra' => 'Contra Voucher',
        ],
        'number_format' => '{type}-{year}-{sequence}',
        'sequence_reset' => 'yearly', // yearly, monthly, never
    ],

    /*
    |--------------------------------------------------------------------------
    | Report Settings
    |--------------------------------------------------------------------------
    */
    'reports' => [
        'date_format' => 'd-M-Y',
        'currency_format' => [
            'decimal_separator' => '.',
            'thousands_separator' => ',',
        ],
        'export_formats' => ['pdf', 'xlsx', 'csv'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Donor Types
    |--------------------------------------------------------------------------
    */
    'donor_types' => [
        'bilateral' => 'Bilateral',
        'multilateral' => 'Multilateral',
        'foundation' => 'Foundation',
        'corporate' => 'Corporate',
        'individual' => 'Individual',
        'government' => 'Government',
    ],

    /*
    |--------------------------------------------------------------------------
    | Project Status
    |--------------------------------------------------------------------------
    */
    'project_status' => [
        'draft' => 'Draft',
        'pending_approval' => 'Pending Approval',
        'approved' => 'Approved',
        'active' => 'Active',
        'on_hold' => 'On Hold',
        'completed' => 'Completed',
        'closed' => 'Closed',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tax Settings (Afghanistan)
    |--------------------------------------------------------------------------
    */
    'tax' => [
        'withholding_rates' => [
            'salary' => [
                ['min' => 0, 'max' => 5000, 'rate' => 0],
                ['min' => 5001, 'max' => 12500, 'rate' => 2],
                ['min' => 12501, 'max' => 100000, 'rate' => 10],
                ['min' => 100001, 'max' => null, 'rate' => 20],
            ],
            'contractor' => 7,
            'rental' => 15,
        ],
    ],
];
