<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AssistantChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AssistantController extends Controller
{
    public function __construct(
        protected AssistantChatService $chatService
    ) {
    }

    /**
     * Handle chat message and return assistant reply.
     */
    public function chat(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:4000',
            'conversation_id' => 'nullable|string|max:64',
        ]);

        if ($validator->fails()) {
            return $this->error('Invalid request', 422, $validator->errors()->toArray());
        }

        $user = $request->user();
        if (!$user) {
            return $this->error('Unauthorized', 401);
        }

        try {
            $result = $this->chatService->chat($user, $validator->validated());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Finance Assistant error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->error('The assistant encountered an error. Please try again.', 500);
        }

        return $this->success($result);
    }
}
