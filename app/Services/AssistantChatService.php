<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AssistantChatService
{
    public function __construct(
        protected AssistantContextService $contextService
    ) {
    }

    /**
     * Send user message with finance context and return assistant reply.
     *
     * @param  array{message: string, conversation_id?: string}  $input
     * @return array{reply: string, usage?: array}
     */
    public function chat(\Illuminate\Contracts\Auth\Authenticatable $user, array $input): array
    {
        $message = trim($input['message'] ?? '');
        if ($message === '') {
            return ['reply' => 'Please enter a question or request.'];
        }

        $provider = $this->getProvider();
        $apiKey = $this->getApiKey();
        $model = $this->getModel();
        $baseUrl = $this->getBaseUrl();

        if ($apiKey === '') {
            Log::warning('Finance Assistant: API key not set or empty', ['provider' => $provider]);
            $hint = $provider === 'deepseek'
                ? 'Set DEEPSEEK_API_KEY in .env (get a key from https://platform.deepseek.com).'
                : 'Set OPENAI_API_KEY in .env (get a key from https://platform.openai.com/api-keys).';
            return [
                'reply' => "The finance assistant is not configured. In your backend .env set the API key for {$provider}: {$hint} Then run: php artisan config:clear and restart the backend. Verify with: php artisan assistant:check.",
            ];
        }

        $contextText = $this->contextService->getContextForUser($user);
        $systemPrompt = config('assistant.system_prompt') . "\n\nCurrent financial context (use this to answer):\n" . $contextText;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $message],
        ];

        $url = rtrim($baseUrl, '/') . '/chat/completions';
        $response = Http::withToken($apiKey)
            ->timeout(60)
            ->post($url, [
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => 1024,
                'temperature' => 0.3,
            ]);

        if (!$response->successful()) {
            Log::error('Finance Assistant API error', [
                'provider' => $provider,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return ['reply' => 'Sorry, the assistant is temporarily unavailable. Please try again later.'];
        }

        $data = $response->json();
        $choices = $data['choices'] ?? null;
        $content = '';
        if (is_array($choices) && isset($choices[0]['message']['content'])) {
            $content = (string) $choices[0]['message']['content'];
        }
        $usage = $data['usage'] ?? null;

        $reply = trim($content);
        if ($reply === '') {
            $reply = 'I received an empty response. Please try rephrasing your question.';
        }

        $result = ['reply' => $reply];
        if ($usage) {
            $result['usage'] = $usage;
        }

        return $result;
    }

    public function getProvider(): string
    {
        $p = config('assistant.provider');
        return strtolower((string) $p) === 'deepseek' ? 'deepseek' : 'openai';
    }

    /**
     * Get API key for the current provider (trimmed). Empty string if not set.
     */
    public function getApiKey(): string
    {
        $provider = $this->getProvider();
        $configKey = $provider === 'deepseek' ? 'assistant.deepseek.api_key' : 'assistant.openai.api_key';
        $envKey = $provider === 'deepseek' ? 'DEEPSEEK_API_KEY' : 'OPENAI_API_KEY';

        $key = config($configKey);
        if (is_string($key)) {
            $key = trim($key);
        } else {
            $key = '';
        }
        if ($key !== '') {
            return $key;
        }
        $key = getenv($envKey);
        return is_string($key) ? trim($key) : '';
    }

    public function getModel(): string
    {
        $provider = $this->getProvider();
        $configKey = $provider === 'deepseek' ? 'assistant.deepseek.model' : 'assistant.openai.model';
        $default = $provider === 'deepseek' ? 'deepseek-chat' : 'gpt-4o-mini';
        $model = config($configKey);
        return is_string($model) && trim($model) !== '' ? trim($model) : $default;
    }

    public function getBaseUrl(): string
    {
        $provider = $this->getProvider();
        $configKey = $provider === 'deepseek' ? 'assistant.deepseek.base_url' : 'assistant.openai.base_url';
        return (string) config($configKey);
    }
}
