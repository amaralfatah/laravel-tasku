<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * A model that answers a prompt with text.
 *
 * Two of them exist, and they are not interchangeable in what they need to
 * run: {@see ClaudeCli} shells out to the Claude Code CLI signed in on the
 * machine (no API key, no production), while {@see GeminiApi} calls Google
 * over HTTP with a key (works anywhere, including the Vercel function).
 * The person picks one in the dialog and {@see TextModels} builds it;
 * everything above this interface is the same either way.
 */
interface TextModel
{
    /**
     * @throws RuntimeException when the model cannot be reached or answers with nothing usable
     */
    public function ask(string $prompt): string;
}
