<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use OpenAI\Laravel\Facades\OpenAI;
use Smalot\PdfParser\Parser;

class RagController extends Controller
{
    private const CACHE_KEY     = 'rag:store';
    private const EMBED_MODEL   = 'text-embedding-3-small';
    private const CHAT_MODEL    = 'gpt-4o-mini';
    private const CHUNK_WORDS   = 300;
    private const CHUNK_OVERLAP = 50;
    private const TOP_K         = 4;

    public function upload(Request $request): JsonResponse
    {
        $request->validate(['pdf' => 'required|file|mimes:pdf|max:20480']);

        $parser = new Parser();
        $pdf    = $parser->parseContent($request->file('pdf')->get());
        $text   = $pdf->getText();

        if (! trim($text)) {
            return response()->json(['error' => 'Could not extract text from this PDF.'], 422);
        }

        $chunks = $this->chunkText($text);

        $response = OpenAI::embeddings()->create([
            'model' => self::EMBED_MODEL,
            'input' => $chunks,
        ]);

        $store = array_map(fn ($chunk, int $i) => [
            'text'      => $chunk,
            'embedding' => $response->embeddings[$i]->embedding,
        ], $chunks, array_keys($chunks));

        Cache::store('file')->put(self::CACHE_KEY, $store, now()->addHours(6));

        return response()->json(['success' => true, 'chunks' => count($chunks)]);
    }

    public function query(Request $request): JsonResponse
    {
        $request->validate(['query' => 'required|string|max:1000']);

        $store = Cache::store('file')->get(self::CACHE_KEY);

        if (! $store) {
            return response()->json(['error' => 'No PDF loaded. Please upload a PDF first.'], 422);
        }

        $queryEmbed = OpenAI::embeddings()->create([
            'model' => self::EMBED_MODEL,
            'input' => [$request->string('query')->toString()],
        ]);
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

        $completion = OpenAI::chat()->create([
            'model'    => self::CHAT_MODEL,
            'messages' => [
                [
                    'role'    => 'system',
                    'content' => "Answer the user's question using only the retrieved context below. "
                               . "If the answer is not present in the context, say so clearly.\n\n---\n{$context}",
                ],
                ['role' => 'user', 'content' => $request->string('query')->toString()],
            ],
        ]);

        return response()->json([
            'answer'  => $completion->choices[0]->message->content,
            'sources' => $topChunks->map(fn ($c) => [
                'preview' => mb_substr($c['text'], 0, 130).'…',
                'score'   => round($c['score'], 3),
            ])->values(),
        ]);
    }

    /** @param  array<int,float>  $a
     *  @param  array<int,float>  $b */
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

    /** @return array<int,string> */
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
