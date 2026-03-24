<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\ProcessingService;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramService $telegramService, ProcessingService $processingService): JsonResponse
    {
        $providedSecret = $request->header('X-Telegram-Bot-Api-Secret-Token');

        if (! $telegramService->isValidWebhookSecret($providedSecret)) {
            return response()->json([
                'ok' => false,
            ], 403);
        }

        $payload = $request->all();

        if (! is_array($payload)) {
            return response()->json([
                'ok' => false,
            ], 422);
        }

        $processingService->handleWebhookUpdate($payload);

        return response()->json([
            'ok' => true,
        ]);
    }
}
