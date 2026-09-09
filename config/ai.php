<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Task assistant
    |--------------------------------------------------------------------------
    |
    | Which model plans a change is the user's choice, made in the dialog on
    | every request. The environment only decides which models are on offer:
    | `claude` shells out to the Claude Code CLI signed in on the machine — no
    | API key, and no binary on the Vercel function runtime — while `gemini`
    | calls Google over HTTP with a key and therefore runs anywhere.
    |
    | A model with `enabled` false is not offered and cannot be asked for.
    |
    */

    'enabled' => (bool) env('AI_ASSISTANT', true),

    // Preselected in the dialog when the person has no saved choice yet. Falls
    // back to whatever else is available when this one is not.
    'default' => env('AI_MODEL', 'claude'),

    'models' => [

        'claude' => [
            'label' => 'Claude CLI',

            // The CLI does not exist on the Vercel function runtime, so a
            // production deployment offers it only if somebody says it is there.
            'enabled' => (bool) env('AI_CLAUDE', env('APP_ENV') !== 'production'),

            'binary' => env('CLAUDE_BIN', 'claude'),

            // An alias ('sonnet', 'opus') or a full model name. Sonnet is the
            // default because planning a handful of tasks is not opus work.
            'model' => env('CLAUDE_MODEL', 'sonnet'),

            'timeout' => (int) env('CLAUDE_TIMEOUT', 120),
        ],

        'gemini' => [
            'label' => 'Gemini',

            'enabled' => (bool) env('AI_GEMINI', env('GEMINI_API_KEY') !== null),

            'api_key' => env('GEMINI_API_KEY'),

            'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),

            'timeout' => (int) env('GEMINI_TIMEOUT', 60),
        ],

    ],

    // How many of the project's tasks are described to the model. The whole
    // point is that it can name an existing task to update or delete, and a
    // very large project would otherwise blow the prompt up.
    'context_tasks' => (int) env('AI_CONTEXT_TASKS', 150),

];
