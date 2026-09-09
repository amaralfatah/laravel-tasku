<?php

namespace App\Services\Ai;

use InvalidArgumentException;

/**
 * The models on offer, and how to build one.
 *
 * Which model plans a change is picked in the dialog per request, so nothing
 * here is bound once for the process. The environment only decides what may be
 * offered: a model whose `enabled` flag is false is missing from
 * {@see options()} and refused by {@see make()}, which is what keeps a person
 * from asking a Vercel deployment for a CLI that is not installed on it.
 */
class TextModels
{
    /**
     * The models a person may choose from, as a select offers them.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function options(): array
    {
        $options = [];

        foreach ((array) config('ai.models') as $key => $model) {
            if (($model['enabled'] ?? false) === true) {
                $options[] = ['value' => (string) $key, 'label' => (string) $model['label']];
            }
        }

        return $options;
    }

    /**
     * The model to preselect — the first one on offer, which is the CLI on a
     * developer machine and Gemini on a deployment. The panel overrides it
     * with whatever this browser picked last.
     */
    public function default(): ?string
    {
        return $this->options()[0]['value'] ?? null;
    }

    public function has(string $key): bool
    {
        return in_array($key, array_column($this->options(), 'value'), true);
    }

    /**
     * @throws InvalidArgumentException when the model is unknown or switched off
     */
    public function make(string $key): TextModel
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("Model AI [{$key}] tidak tersedia.");
        }

        return match ($key) {
            'claude' => new ClaudeCli,
            'gemini' => new GeminiApi,
            default => throw new InvalidArgumentException("Model AI [{$key}] tidak dikenal."),
        };
    }
}
