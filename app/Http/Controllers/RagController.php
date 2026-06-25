<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Laravel\Facades\OpenAI;
use Smalot\PdfParser\Parser;

class RagController extends Controller
{
    private const CACHE_KEY     = 'rag:chunks';
    private const CHAT_MODEL    = 'gpt-4o-mini';
    private const CHUNK_WORDS   = 400;
    private const CHUNK_OVERLAP = 40;
    private const TOP_K         = 3;
    private const MAX_ATTEMPTS  = 5;
    private const FREE_TIER_DELAY = 21;

    public function upload(Request $request): JsonResponse
    {
        set_time_limit(300);
        $request->validate(['pdf' => 'required|file|mimes:pdf|max:20480']);

        try {
            $parser = new Parser();
            $pdf    = $parser->parseContent($request->file('pdf')->get());
            $text   = $pdf->getText();

            if (! trim($text)) {
                return response()->json(['error' => 'Could not extract text from this PDF.'], 422);
            }

            $chunks = $this->chunkText($text);

            Cache::store('file')->put(self::CACHE_KEY, $chunks, now()->addHours(6));

            return response()->json(['success' => true, 'chunks' => count($chunks)]);

        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function query(Request $request): JsonResponse
    {
        set_time_limit(300);
        $request->validate(['query' => 'required|string|max:1000']);

        $chunks = Cache::store('file')->get(self::CACHE_KEY);

        if (! $chunks) {
            return response()->json(['error' => 'No PDF loaded. Please upload a PDF first.'], 422);
        }

        try {
            $query     = $request->string('query')->toString();
            $topChunks = $this->retrieve($query, $chunks);
            $context   = collect($topChunks)
                ->map(fn ($c, int $i) => '[Chunk '.($i + 1)."]\n{$c['text']}")
                ->implode("\n\n");

            $completion = $this->withRetry(fn () => OpenAI::chat()->create([
                'model'    => self::CHAT_MODEL,
                'messages' => [
                    [
                        'role'    => 'system',
                        'content' => "Answer the user's question using only the retrieved context below. "
                                   . "If the answer is not in the context, say so clearly.\n\n---\n{$context}",
                    ],
                    ['role' => 'user', 'content' => $query],
                ],
            ]));

            return response()->json([
                'answer'  => $completion->choices[0]->message->content ?? '',
                'sources' => array_map(fn ($c) => [
                    'preview' => mb_substr($c['text'], 0, 130).'…',
                    'score'   => round($c['score'], 3),
                ], $topChunks),
            ]);

        } catch (RateLimitException $e) {
            return response()->json(['error' => $this->rateLimitMessage($e)], 429);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function retrieve(string $query, array $chunks): array
    {
        $queryTerms = $this->tokenize($query);
        $idf        = $this->idf($chunks);

        $scored = array_map(function (string $chunk) use ($queryTerms, $idf) {
            $chunkTerms = array_count_values($this->tokenize($chunk));
            $chunkLen   = max(array_sum($chunkTerms), 1);
            $score      = 0.0;

            foreach ($queryTerms as $term) {
                if (isset($chunkTerms[$term])) {
                    $tf     = $chunkTerms[$term] / $chunkLen;
                    $score += $tf * ($idf[$term] ?? 0.0);
                }
            }

            return ['text' => $chunk, 'score' => $score];
        }, $chunks);

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, self::TOP_K);
    }

    private function idf(array $chunks): array
    {
        $n  = count($chunks);
        $df = [];

        foreach ($chunks as $chunk) {
            foreach (array_unique($this->tokenize($chunk)) as $term) {
                $df[$term] = ($df[$term] ?? 0) + 1;
            }
        }

        $idf = [];
        foreach ($df as $term => $freq) {
            $idf[$term] = log(($n + 1) / ($freq + 1)) + 1;
        }

        return $idf;
    }

    private function tokenize(string $text): array
    {
        $text  = strtolower($text);
        $text  = preg_replace('/[^a-z0-9\s]/', ' ', $text) ?? '';
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $stopwords = ['the','a','an','and','or','but','in','on','at','to','for',
                      'of','with','by','from','is','it','this','that','was','are'];

        return array_values(array_filter(
            $words,
            fn ($w) => strlen($w) > 2 && ! in_array($w, $stopwords, true)
        ));
    }

    private function chunkText(string $text): array
    {
        $words  = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $chunks = [];
        $total  = count($words);
        $step   = self::CHUNK_WORDS - self::CHUNK_OVERLAP;

        for ($i = 0; $i < $total; $i += $step) {
            $chunks[] = implode(' ', array_slice($words, $i, self::CHUNK_WORDS));
        }

        return $chunks;
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
                sleep(min(max($retryAfter, self::FREE_TIER_DELAY), 60));
            }
        }
    }

    private function rateLimitMessage(RateLimitException $e): string
    {
        try {
            $body    = json_decode((string) $e->response->getBody(), true);
            $message = $body['error']['message'] ?? '';
            $code    = $body['error']['code']    ?? '';

            if ($code === 'insufficient_quota' || str_contains($message, 'quota')) {
                return 'Your OpenAI free credits have expired. Add a payment method at platform.openai.com/settings/billing to continue.';
            }

            if ($message) {
                return "OpenAI: {$message}";
            }
        } catch (\Throwable) {}

        $retryAfter = (int) ($e->response->getHeaderLine('retry-after') ?: 0);

        return $retryAfter > 0
            ? "Rate limit reached. Please try again in {$retryAfter} seconds."
            : 'Rate limit reached. Please wait a moment and try again.';
    }
}
