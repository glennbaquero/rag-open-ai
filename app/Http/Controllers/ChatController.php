<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Laravel\Facades\OpenAI;

class ChatController extends Controller
{
    private const CHAT_MODEL   = 'gpt-4o-mini';
    private const TTL          = 120;
    private const MAX_ATTEMPTS = 3;

    private const PRESET_CONTEXTS = [
        'color' => 'My favorite color is red.',
        'food'  => 'My favorite food is green apples because I love green.',
    ];

    private const TOOLS = [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'get_preference_color',
                'description' => "Returns the color that best reflects the user's current stated preference. "
                               . 'Call this whenever the user asks about a color, preference, or favourite thing.',
                'parameters'  => ['type' => 'object', 'properties' => [], 'required' => []],
            ],
        ],
    ];

    public function message(Request $request): JsonResponse
    {
        $request->validate([
            'session_id' => 'required|string|uuid',
            'message'    => 'required|string|max:2000',
        ]);

        $sessionId = $request->string('session_id')->toString();
        $session   = $this->loadSession($sessionId);

        $session['history'][] = ['role' => 'user', 'content' => $request->string('message')->toString()];

        $systemMessages = [['role' => 'system', 'content' => $session['context']]];
        $allMessages    = array_merge($systemMessages, $session['history']);

        try {
            $response = $this->withRetry(fn () => OpenAI::chat()->create([
                'model'       => self::CHAT_MODEL,
                'messages'    => $allMessages,
                'tools'       => self::TOOLS,
                'tool_choice' => 'auto',
            ]));

            $assistantMsg = $response->choices[0]->message;
            $toolOutput   = null;

            $session['history'][] = $assistantMsg->toArray();

            if (! empty($assistantMsg->toolCalls)) {
                foreach ($assistantMsg->toolCalls as $toolCall) {
                    $color                = $this->extractColor($session['context']);
                    $toolOutput           = $color;
                    $session['history'][] = [
                        'role'         => 'tool',
                        'tool_call_id' => $toolCall->id,
                        'content'      => $color,
                    ];
                }

                $response = $this->withRetry(fn () => OpenAI::chat()->create([
                    'model'    => self::CHAT_MODEL,
                    'messages' => array_merge($systemMessages, $session['history']),
                ]));
                $assistantMsg         = $response->choices[0]->message;
                $session['history'][] = $assistantMsg->toArray();
            }

            $this->saveSession($sessionId, $session);

            return response()->json([
                'reply'      => $assistantMsg->content,
                'toolOutput' => $toolOutput,
                'context'    => $session['context'],
                'history'    => $this->buildDisplayHistory($session['history']),
            ]);

        } catch (RateLimitException $e) {
            return response()->json(['error' => $this->rateLimitMessage($e)], 429);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function switchContext(Request $request): JsonResponse
    {
        $request->validate([
            'session_id'     => 'required|string|uuid',
            'context_key'    => 'nullable|string|in:color,food',
            'custom_context' => 'nullable|string|max:1000',
        ]);

        $sessionId = $request->string('session_id')->toString();
        $session   = $this->loadSession($sessionId);

        $session['context'] = $request->filled('custom_context')
            ? $request->string('custom_context')->toString()
            : self::PRESET_CONTEXTS[$request->input('context_key', 'color')];

        $this->saveSession($sessionId, $session);

        return response()->json(['success' => true, 'context' => $session['context']]);
    }

    public function clearSession(Request $request, string $sessionId): JsonResponse
    {
        Cache::forget("chat:{$sessionId}");

        return response()->json(['success' => true]);
    }

    private function withRetry(callable $fn): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $fn();
            } catch (RateLimitException $e) {
                if (++$attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }

                $retryAfter = (int) ($e->response->getHeaderLine('retry-after') ?: 0);
                $wait       = $retryAfter > 0 ? $retryAfter : (int) pow(2, $attempt + 2);

                sleep(min($wait, 60));
            }
        }
    }

    private function rateLimitMessage(RateLimitException $e): string
    {
        $retryAfter = (int) ($e->response->getHeaderLine('retry-after') ?: 0);

        return $retryAfter > 0
            ? "OpenAI rate limit reached. Please try again in {$retryAfter} seconds."
            : 'OpenAI rate limit reached. Please wait a moment and try again.';
    }

    private function loadSession(string $id): array
    {
        return Cache::get("chat:{$id}", [
            'history' => [],
            'context' => self::PRESET_CONTEXTS['color'],
        ]);
    }

    private function saveSession(string $id, array $session): void
    {
        Cache::put("chat:{$id}", $session, now()->addMinutes(self::TTL));
    }

    private function extractColor(string $context): string
    {
        $colors = ['red', 'green', 'blue', 'yellow', 'purple', 'orange', 'pink', 'black', 'white', 'brown'];
        $lower  = strtolower($context);

        foreach ($colors as $color) {
            if (str_contains($lower, $color)) {
                return ucfirst($color);
            }
        }

        return 'Unknown';
    }

    private function buildDisplayHistory(array $history): array
    {
        $display = [];

        foreach ($history as $m) {
            if ($m['role'] === 'user') {
                $display[] = ['role' => 'user', 'content' => (string) $m['content']];
            } elseif ($m['role'] === 'assistant' && is_string($m['content'] ?? null) && $m['content'] !== '') {
                $display[] = ['role' => 'assistant', 'content' => (string) $m['content']];
            }
        }

        return $display;
    }
}
