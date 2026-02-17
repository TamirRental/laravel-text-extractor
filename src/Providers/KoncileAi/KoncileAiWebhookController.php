<?php

declare(strict_types=1);

namespace TamirRental\DocumentExtraction\Providers\KoncileAi;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use TamirRental\DocumentExtraction\Services\DocumentExtractionService;

class KoncileAiWebhookController extends Controller
{
    public function handle(Request $request, DocumentExtractionService $service): JsonResponse
    {
        if (!$this->verifySignature($request)) {
            Log::warning('Koncile AI webhook signature verification failed');

            return response()->json(['error' => 'Invalid signature'], 403);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        $taskId = (string) ($payload['task_id'] ?? '');
        $status = (string) ($payload['status'] ?? '');

        if (!$taskId) {
            return response()->json(['error' => 'Missing task_id'], 400);
        }

        Log::info('Koncile AI webhook received', [
            'task_id' => $taskId,
            'status' => $status,
        ]);

        match ($status) {
            'DONE' => $service->completeExtraction(
                $taskId,
                $payload['General_fields'] ?? [],
                $payload['Line_fields'] ?? [],
                $payload,
            ),
            'FAILED' => $service->failExtraction(
                $taskId,
                $payload['error_message'] ?? 'Extraction failed on provider side.',
            ),
            default => Log::info('Koncile AI webhook status ignored', [
                'task_id' => $taskId,
                'status' => $status,
            ]),
        };

        return response()->json(['message' => 'Webhook processed']);
    }

    private function verifySignature(Request $request): bool
    {
        /** @var ?string $secret */
        $secret = config('document-extraction.providers.koncile_ai.webhook_secret');

        if (!$secret) {
            if (app()->environment('production')) {
                Log::error('Koncile AI webhook secret not configured in production');

                return false;
            }

            Log::warning('Koncile AI webhook signature verification skipped - no secret configured');

            return true;
        }

        $signature = $request->header('X-Koncile-Signature', '');
        $timestamp = $request->header('X-Koncile-Timestamp', '');

        if (!$signature || !$timestamp) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > 300) {
            Log::warning('Koncile AI webhook timestamp too old', [
                'timestamp' => $timestamp,
            ]);

            return false;
        }

        $payload = "{$timestamp}.{$request->getContent()}";
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expectedSignature, $signature);
    }
}
