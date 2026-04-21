<?php

namespace App\Console\Commands;

use App\Services\AssistantChatService;
use Illuminate\Console\Command;

class AssistantCheck extends Command
{
    protected $signature = 'assistant:check';
    protected $description = 'Check if the Finance Assistant (OpenAI or DeepSeek) is configured';

    public function handle(AssistantChatService $chatService): int
    {
        $this->info('Checking Finance Assistant configuration...');

        $provider = $chatService->getProvider();
        $apiKey = $chatService->getApiKey();
        $model = $chatService->getModel();

        $this->info('Provider: ' . $provider);

        if ($apiKey === '') {
            $var = $provider === 'deepseek' ? 'DEEPSEEK_API_KEY' : 'OPENAI_API_KEY';
            $url = $provider === 'deepseek' ? 'https://platform.deepseek.com' : 'https://platform.openai.com/api-keys';
            $this->error("{$var} is not set or is empty.");
            $this->line('');
            $this->line('To fix:');
            $this->line('  1. Open <comment>backend/.env</comment>');
            $this->line('  2. Set <comment>ASSISTANT_PROVIDER=' . $provider . '</comment> (already set)');
            $this->line('  3. Set <comment>' . $var . '=your-key</comment> (get a key from ' . $url . ')');
            $this->line('  4. Run <comment>php artisan config:clear</comment>');
            $this->line('  5. Restart your web server or <comment>php artisan serve</comment>');
            return self::FAILURE;
        }

        $this->info('API key is set (' . strlen($apiKey) . ' characters).');
        $this->info('Model: ' . $model);
        $this->comment('Finance Assistant is configured.');
        return self::SUCCESS;
    }
}
