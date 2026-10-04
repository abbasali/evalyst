<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->configureDevCommands();
    }

    /**
     * `composer dev` must also work the `ai` queue (AI jobs never run on `default`).
     */
    protected function configureDevCommands(): void
    {
        if (! $this->app->runningInConsole() || $this->app->isProduction()) {
            return;
        }

        DevCommands::except('queue');
        DevCommands::artisan('queue:listen --queue=ai,default --tries=1 --timeout=0', 'queues');
    }

    /**
     * Rate limits for AI calls and expensive instructor actions.
     */
    protected function configureRateLimiting(): void
    {
        // Used as job middleware (RateLimited('openai')) on every AI job.
        RateLimiter::for('openai', fn () => Limit::perMinute((int) config('evalyst.ai.rate_limit_per_minute')));

        // Students joining with a code, per IP. A friendly inline error instead of a 429 page.
        // Per browser session, with a generous per-IP ceiling: a whole class often shares one IP (D-022).
        RateLimiter::for('join', function (Request $request) {
            $tooMany = fn () => back()->withErrors(['code' => __('Too many tries. Wait a minute and try again.')]);

            return [
                Limit::perMinute((int) config('evalyst.student.join_attempts_per_minute'))
                    ->by('session:'.$request->session()->getId())->response($tooMany),
                Limit::perMinute((int) config('evalyst.student.join_attempts_per_ip_per_minute'))
                    ->by('ip:'.$request->ip())->response($tooMany),
            ];
        });

        // Autosaves: generous, but stops a runaway client.
        RateLimiter::for('attempt-saves', fn (Request $request) => Limit::perMinute(120)->by($request->session()->getId() ?: $request->ip()));

        RateLimiter::for('question-generation', fn (Request $request) => Limit::perMinutes(10, 5)->by($request->user()?->id ?: $request->ip()));
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
