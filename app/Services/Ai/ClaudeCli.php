<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Thin wrapper around the local Claude Code CLI.
 *
 * The binary carries its own sign in, so the application holds no API key and
 * sends nothing anywhere itself — it pipes a prompt into `claude -p` and reads
 * the JSON back. That also means the feature only exists where the binary
 * does: a developer machine or a self hosted server, never the Vercel
 * function runtime (see config/ai.php).
 */
class ClaudeCli implements TextModel
{
    /**
     * Tools the planner has no use for. It is asked for JSON, not for work on
     * the machine, and the session already starts in an empty directory.
     */
    protected const DENIED_TOOLS = 'Bash,Edit,Write,Read,Glob,Grep,WebFetch,WebSearch,Task,NotebookEdit';

    /**
     * Ask Claude for a single answer and return its text.
     *
     * The prompt goes in on stdin rather than as an argument: a project's task
     * list runs to thousands of characters, and Windows caps a command line at
     * around 32k.
     *
     * @throws RuntimeException when the CLI is missing, times out or fails
     */
    public function ask(string $prompt): string
    {
        $result = Process::path($this->workingDirectory())
            ->timeout((int) config('ai.models.claude.timeout'))
            ->env($this->environment())
            ->input($prompt)
            ->run($this->command());

        if ($result->failed()) {
            throw new RuntimeException(
                trim($result->errorOutput()) ?: 'Claude CLI tidak bisa dijalankan.',
            );
        }

        /** @var array{result?: string, is_error?: bool, subtype?: string}|null $payload */
        $payload = json_decode($result->output(), true);

        if (! is_array($payload) || ! isset($payload['result'])) {
            throw new RuntimeException('Jawaban Claude CLI tidak bisa dibaca.');
        }

        if (($payload['is_error'] ?? false) === true) {
            throw new RuntimeException((string) $payload['result']);
        }

        return (string) $payload['result'];
    }

    /**
     * @return array<int, string>
     */
    protected function command(): array
    {
        $command = [
            (string) config('ai.models.claude.binary'),
            '-p',
            '--output-format', 'json',
            '--disallowed-tools', self::DENIED_TOOLS,
        ];

        $model = config('ai.models.claude.model');

        if (is_string($model) && $model !== '') {
            $command[] = '--model';
            $command[] = $model;
        }

        return $command;
    }

    /**
     * The environment the CLI needs, forwarded explicitly.
     *
     * A web request does not carry the shell's environment the way `tinker`
     * does — `php artisan serve` hands its child a short whitelist, and
     * php-fpm clears the environment outright. The CLI is a Bun binary, which
     * refuses to make any network request without `SystemRoot` on Windows
     * ("Bun needs this set in order for network requests to work"), and it
     * reads its sign in from the home directory, so a missing `USERPROFILE`
     * would log it out. Symfony merges these over the inherited environment,
     * so anything already present stays.
     *
     * @return array<string, string>
     */
    protected function environment(): array
    {
        $forwarded = [
            // Windows: Bun's own requirement, plus where the sign in lives.
            'SystemRoot', 'SystemDrive', 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA',
            'TEMP', 'TMP', 'PATH', 'PATHEXT', 'COMSPEC',
            // POSIX equivalents, for a self hosted server.
            'HOME', 'USER', 'LANG', 'TMPDIR', 'XDG_CONFIG_HOME',
        ];

        $environment = [];

        foreach ($forwarded as $name) {
            $value = getenv($name) ?: ($_SERVER[$name] ?? null);

            if (is_string($value) && $value !== '') {
                $environment[$name] = $value;
            }
        }

        // Bun refuses to start without this one, and a stripped environment is
        // exactly the case this method exists for.
        if (windows_os() && ! isset($environment['SystemRoot'])) {
            $environment['SystemRoot'] = 'C:\\Windows';
        }

        return $environment;
    }

    /**
     * An empty directory, so the CLI does not auto-load the repository's own
     * CLAUDE.md into every request — that alone was 23k tokens of context the
     * planner has no use for.
     */
    protected function workingDirectory(): string
    {
        $path = storage_path('app/ai');

        File::ensureDirectoryExists($path);

        return $path;
    }
}
