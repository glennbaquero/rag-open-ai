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
    private const CACHE_KEY          = 'rag:store';
    private const EMBED_MODEL        = 'text-embedding-3-small';
    private const CHAT_MODEL         = 'gpt-4o-mini';
    private const CHUNK_WORDS        = 600;   // larger chunks → fewer total API calls
    private const CHUNK_OVERLAP      = 60;
    private const TOP_K              = 3;
    private const MAX_ATTEMPTS       = 5;
    private const EMBED_BATCH        = 20;
    private const FREE_TIER_DELAY    = 21;    // seconds between API calls (3 RPM = 1 per 20 s)

    public function upload(Request $request): JsonResponse
    {
        $request->validate(['pdf' => 'required|file|mimes:pdf|max:20480']);

        try {
            $parser = new Parser();
            $pdf    = $parser->parseContent($request->file('pdf')->get());
            $text   = $pdf->getText();

            if (! trim($text)) {
                return response()->json(['error' => 'Could not extract text from this PDF.'], 422);
            }

            $chunks = $this->chunkText($text);
            $store  = $this->embedChunks($chunks);

            Cache::store('file')->put(self::CACHE_KEY, $store, now()->addHours(6));

            return response()->json(['success' => true, 'chunks' => count($chunks)]);

        } catch (RateLimitException $e) {
            return response()->json([
                'error' => $this->rateLimitMessage($e),
            ], 429);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function query(Request $request): JsonResponse
    {
        $request->validate(['query' => 'required|string|max:1000']);

        $store = Cache::store('file')->get(self::CACHE_KEY);

        if (! $store) {
            return response()->json(['error' => 'No PDF loaded. Please upload a PDF first.'], 422);
        }

        try {
            $queryEmbed = $this->withRetry(fn () => OpenAI::embeddings()->create([
                'model' => self::EMBED_MODEL,
                'input' => [$request->string('query')->toString()],
            ]));
            $queryVec = $queryEmbed->embeddings[0]->embedding;

            $topChunks = collect($store)
                ->map(fn ($chunk) => array_merge($chunk, [
                    'score' => $this->cosineSimilarity($queryVec, $chunk['embedding']),
                ]))
                ->sortByDesc('score')
                ->take(self::TOP_K)
                ->values();

            $context = $topChunks
                ->map(fn ($c, int $i) => '[Chunk '.($i + 1)."]\n{$c['text']}")
                ->implode("\n\n");

            $completion = $this->withRetry(fn () => OpenAI::chat()->create([
                'model'    => self::CHAT_MODEL,
                'messages' => [
                    [
                        'role'    => 'system',
                        'content' => "Answer the user's question using only the retrieved context below. "
                                   . "If the answer is not present in the context, say so clearly.\n\n---\n{$context}",
                    ],
                    ['role' => 'user', 'content' => $request->string('query')->toString()],
                ],
            ]));

            return response()->json([
                'answer'  => $completion->choices[0]->message->content,
                'sources' => $topChunks->map(fn ($c) => [
                    'preview' => mb_substr($c['text'], 0, 130).'…',
                    'score'   => round($c['score'], 3),
                ])->values(),
            ]);

        } catch (RateLimitException $e) {
            return response()->json(['error' => $this->rateLimitMessage($e)], 429);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Embed chunks in small batches so we stay within the tokens-per-minute
     * limit on low-tier OpenAI accounts. A 1-second pause between batches
     * keeps the request rate well below the default 60 RPM ceiling.
     *
     * @param  array<int,string>  $chunks
     * @return array<int,array{text:string,embedding:array<int,float>}>
     */
    private function embedChunks(array $chunks): array
    {
        $store   = [];
        $batches = array_chunk($chunks, self::EMBED_BATCH);

        foreach ($batches as $batchIndex => $batch) {
            if ($batchIndex > 0) {
                sleep(self::FREE_TIER_DELAY);
            }

            $response = $this->withRetry(fn () => OpenAI::embeddings()->create([
                'model' => self::EMBED_MODEL,
                'input' => $batch,
            ]));

            foreach ($response->embeddings as $i => $embedding) {
                $store[] = [
                    'text'      => $batch[$i],
                    'embedding' => $embedding->embedding,
                ];
            }
        }

        return $store;
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
                $wait       = max($retryAfter, self::FREE_TIER_DELAY);

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

    private function cosineSimilarity(array $a, array $b): float
    {
        $dot  = 0.0;
        $magA = 0.0;
        $magB = 0.0;

        foreach ($a as $i => $val) {
            $dot  += $val * $b[$i];
            $magA += $val * $val;
            $magB += $b[$i] * $b[$i];
        }

        $denom = sqrt($magA) * sqrt($magB);

        return $denom > 0.0 ? $dot / $denom : 0.0;
    }

    private function chunkText(string $text): array
    {
        $words  = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $chunks = [];
        $step   = self::CHUNK_WORDS - self::CHUNK_OVERLAP;

        for ($i = 0; $i < count($words); $i += $step) {
            $chunks[] = implode(' ', array_slice($words, $i, self::CHUNK_WORDS));
        }

        return $chunks;
    }
}
