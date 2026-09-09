<?php

namespace App\Providers;

use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Tenancy::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->passHomeThroughToTheDevServer();
    }

    /**
     * Let `php artisan serve` hand its PHP process the variables the AI
     * assistant's CLI needs.
     *
     * `ServeCommand::$passthroughVariables` is a short whitelist — `PATH` and
     * `SYSTEMROOT` are on it, the home directory is not — so a request served
     * this way spawned a `claude` that could not find its own sign in, and on
     * Windows a Bun binary that refuses to make any network request without
     * `SystemRoot` ("Bun needs this set in order for network requests to
     * work"). The CLI works from `tinker` because that inherits the shell's
     * environment whole.
     *
     * Changing this list only affects the local dev server, and the variables
     * are the machine's own, not secrets.
     */
    protected function passHomeThroughToTheDevServer(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        ServeCommand::$passthroughVariables = array_values(array_unique([
            ...ServeCommand::$passthroughVariables,
            'SYSTEMDRIVE',
            'USERPROFILE',
            'APPDATA',
            'LOCALAPPDATA',
            'TEMP',
            'TMP',
            'PATHEXT',
            'COMSPEC',
            'HOME',
            'TMPDIR',
            'XDG_CONFIG_HOME',
        ]));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
