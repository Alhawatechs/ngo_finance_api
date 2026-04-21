<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Google Sheets API (for budget format import)
    |--------------------------------------------------------------------------
    | Set GOOGLE_SHEETS_API_KEY in .env. The spreadsheet must be shared
    | "Anyone with the link can view" for import to work with API key.
    | Or use a service account and share the sheet with that email.
    */
    'api_key' => env('GOOGLE_SHEETS_API_KEY'),
];
