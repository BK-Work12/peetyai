<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\WebhookMessageRequest;
use App\Services\WhatsApp\WhatsAppWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WhatsappWebhookController extends Controller
{
    public function verify(Request $request): Response|JsonResponse
    {
        $verifyToken = config('services.whatsapp.verify_token');
        $mode = $request->query('hub_mode') ?? $request->query('hub.mode');
        $token = $request->query('hub_verify_token') ?? $request->query('hub.verify_token');
        $challenge = $request->query('hub_challenge') ?? $request->query('hub.challenge');

        if ($mode === 'subscribe' && $token === $verifyToken) {
            return response((string) $challenge, 200);
        }

        return response()->json(['message' => 'Invalid verification token'], 403);
    }

    public function store(WebhookMessageRequest $request, WhatsAppWebhookService $webhookService): JsonResponse
    {
        $webhookService->processInboundPayload($request->validated());

        return response()->json(['status' => 'accepted']);
    }
}
