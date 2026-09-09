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
        if (config('ai.enabled') !== true) {
            return [];
        }

        $options = [];

        foreach ((array) config('ai.models') as $key => $model) {
            if (($model['enabled'] ?? false) === true) {
                $options[] = ['value' => (string) $key, 'label' => (string) $model['label']];
            }
        }

        return $options;
    }

    /**
     * The model to preselect: the configured default when it is available, and
     * otherwise the first one that is.
     */
    public function default(): ?string
    {
        $available = array_column($this->options(), 'value');

        if ($available === []) {
            return null;
        }

        return in_array(config('ai.default'), $available, true)
            ? (string) config('ai.default')
            : $available[0];
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
