<?php

return [

    'provider' => trim((string) env('ASSISTANT_PROVIDER', 'openai')), // "openai" or "deepseek"

    'openai' => [
        'api_key' => trim((string) env('OPENAI_API_KEY', '')),
        'model' => trim((string) env('OPENAI_MODEL', 'gpt-4o-mini')),
        'base_url' => 'https://api.openai.com/v1',
    ],

    'deepseek' => [
        'api_key' => trim((string) env('DEEPSEEK_API_KEY', '')),
        'model' => trim((string) env('DEEPSEEK_MODEL', 'deepseek-chat')),
        'base_url' => 'https://api.deepseek.com/v1',
    ],

    'system_prompt' => env('ASSISTANT_SYSTEM_PROMPT', <<<'PROMPT'
You are a helpful finance assistant for an ERP system. You answer questions about the organization's financial data based on the context provided. You must NOT perform financial transactions, approve vouchers, or modify data—only answer questions and summarize. Be concise and professional. When referring to amounts, use the currency and numbers from the context. If the user asks something not covered by the context, say so and suggest they check the relevant report or module.
PROMPT
    ),

];
