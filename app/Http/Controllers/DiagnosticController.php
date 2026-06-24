<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Laravel\Facades\OpenAI;

class DiagnosticController extends Controller
{
    public function testConnection(): JsonResponse
    {
        $key = config('openai.api_key');

        if (! $key || str_starts_with($key, 'sk-your')) {
            return response()->json([
                'ok'      => false,
                'problem' => 'OPENAI_API_KEY is not set in your .env file.',
            ]);
        }

        try {
            OpenAI::models()->list();

            return response()->json(['ok' => true, 'message' => 'API key is valid and working.']);

        } catch (RateLimitException $e) {
            $body    = json_decode((string) $e->response->getBody(), true);
            $message = $body['error']['message'] ?? 'Rate limit or quota error.';
            $code    = $body['error']['code']    ?? '';

            if ($code === 'insufficient_quota' || str_contains($message, 'quota')) {
                return response()->json([
                    'ok'      => false,
                    'problem' => 'insufficient_quota',
                    'message' => 'Your free trial credits have expired. Visit platform.openai.com/settings/billing to add a payment method.',
                ]);
            }

            return response()->json(['ok' => false, 'problem' => 'rate_limit', 'message' => $message]);

        } catch (ErrorException $e) {
            return response()->json([
                'ok'      => false,
                'problem' => $e->getErrorCode() ?? 'api_error',
                'message' => $e->getMessage(),
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'problem' => 'unknown',
                'message' => $e->getMessage(),
            ]);
        }
    }
}
