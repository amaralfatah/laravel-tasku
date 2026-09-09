<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google's Gemini API over plain HTTP, authenticated with an API key.
 *
 * The driver to use where the Claude CLI cannot live — the Vercel function
 * runtime carries no binaries and no `~/.claude` sign in, but it can make an
 * outbound request. `responseMimeType: application/json` asks Gemini for bare
 * JSON, so the planner is spared unwrapping a ```json fence.
 */
class GeminiApi implements TextModel
{
    protected const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function ask(string $prompt): string
    {
        $key = (string) config('ai.models.gemini.api_key');

        if ($key === '') {
            throw new RuntimeException('GEMINI_API_KEY belum diisi.');
        }

        $response = Http::timeout((int) config('ai.models.gemini.timeout'))
            ->withHeaders(['x-goog-api-key' => $key])
            ->post(sprintf(self::ENDPOINT, config('ai.models.gemini.model')), [
                'contents' => [
                    ['parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    // A plan is a reading of one sentence, not a creative act.
                    'temperature' => 0,
                    'responseMimeType' => 'application/json',
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                (string) ($response->json('error.message') ?? 'Gemini menolak permintaan ini.'),
            );
        }

        $text = $response->json('candidates.0.content.parts.0.text');

        if (! is_string($text) || trim($text) === '') {
            throw new RuntimeException('Gemini tidak mengembalikan jawaban.');
        }

        return $text;
    }
}
