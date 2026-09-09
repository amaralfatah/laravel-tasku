<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Task assistant
    |--------------------------------------------------------------------------
    |
    | Which model plans a change is the user's choice, made in the chat panel
    | on every request, and which models are on offer follows from what the
    | machine can actually run — so there is nothing here to switch on.
    |
    | `claude` shells out to the Claude Code CLI already signed in on the
    | machine: no API key, and no binary on the Vercel function runtime, which
    | is why it is offered outside production only. `gemini` calls Google over
    | HTTP and exists exactly when a key does, production included.
    |
    | Two environment variables, both optional: GEMINI_API_KEY turns Gemini on,
    | and CLAUDE_BIN names the binary when it is not on the PATH.
    |
    */

    'models' => [

        'claude' => [
            'label' => 'Claude CLI',

            'enabled' => env('APP_ENV') !== 'production',

            'binary' => env('CLAUDE_BIN', 'claude'),

            // An alias ('sonnet', 'opus') or a full model name. Sonnet is the
            // choice because planning a handful of tasks is not opus work.
            'model' => 'sonnet',

            'timeout' => 120,
        ],

        'gemini' => [
            'label' => 'Gemini',

            'enabled' => env('GEMINI_API_KEY') !== null,

            'api_key' => env('GEMINI_API_KEY'),

            'model' => 'gemini-2.5-flash',

            'timeout' => 60,
        ],

    ],

    // How many of the project's tasks are described to the model. The whole
    // point is that it can name an existing task to update or delete, and a
    // very large project would otherwise blow the prompt up.
    'context_tasks' => 150,

];
