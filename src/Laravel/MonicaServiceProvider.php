<?php

declare(strict_types=1);

namespace Monica\Laravel;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\ServiceProvider;
use Monica\Client;
use Monica\Monica;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Throwable;

/**
 * Laravel catches every exception of a request, a command or a job in its own
 * handler, so the handlers `Client` installs never see them. This hooks the
 * client into that handler instead, which also keeps `$dontReport` in force.
 *
 * The client's own exception and error handlers stay off: Laravel turns a PHP
 * warning into an `ErrorException` and a fatal error into a `FatalError` and
 * reports both, so capturing them in PHP's handlers too would send each twice.
 * Fatal errors are left to `Client::handleShutdown()`, which frees the memory
 * reserve before building the event and flushes afterwards.
 */
final class MonicaServiceProvider extends ServiceProvider
{
    // The app is booted again for every test of the host's suite. One client
    // per process keeps that from piling up shutdown functions and reserves.
    private static ?Client $client = null;

    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2) . '/config/monica.php', 'monica');

        // A key left blank in .env (`MONICA_RELEASE=`) comes back as '', and
        // means "the default" rather than an empty release or a sample rate
        // of 0.
        $options = array_filter((array) $this->app['config']->get('monica', []), static function ($value): bool {
            return $value !== null && $value !== '';
        });
        // No DSN means MONICA is off for this environment (local, CI), not a
        // misconfiguration worth failing every request over.
        if (trim((string) ($options['dsn'] ?? '')) === '') {
            return;
        }
        if (self::$client === null) {
            self::$client = Monica::init(['auto_capture' => false] + $options);
            register_shutdown_function([self::$client, 'handleShutdown']);
        }
        $client = self::$client;
        $hook = static function ($handler) use ($client): void {
            $handler->reportable(static function (Throwable $exception) use ($client): void {
                if (!$exception instanceof FatalError) {
                    $client->captureException($exception);
                }
            });
        };

        // Hooked when the handler is built rather than fetched in boot():
        // Collision (console) builds the app's handler during its own
        // register() and replaces it with a wrapper. Discovered packages
        // register by name, so this normally runs first and still sees the
        // app's handler. The wrapper is skipped: on Laravel 8 / 9 it has no
        // reportable(), and later it forwards to the handler hooked already.
        if (!$this->app->resolved(ExceptionHandler::class)) {
            $this->app->afterResolving(ExceptionHandler::class, static function ($handler) use ($hook): void {
                if ($handler instanceof Handler && method_exists($handler, 'reportable')) {
                    $hook($handler);
                }
            });
        } else {
            // Registered late (the package taken out of discovery, or another
            // provider resolved the handler first): hook whatever is there.
            // Collision's wrapper on Laravel 8 / 9 has nothing to hook.
            $handler = $this->app->make(ExceptionHandler::class);
            if (method_exists($handler, 'reportable')) {
                $hook($handler);
            }
        }

        // A queue worker never reaches shutdown between jobs. It fires Looping
        // before taking each job, so what the last job reported goes out then,
        // and an idle worker still sends its heartbeat. A worker that stops,
        // including one killed for a job timeout, fires WorkerStopping first.
        $this->app['events']->listen(
            ['Illuminate\Queue\Events\Looping', 'Illuminate\Queue\Events\WorkerStopping'],
            static function () use ($client): void {
                $client->flush();
            }
        );
    }

    public function boot(): void
    {
        $this->publishes(
            [dirname(__DIR__, 2) . '/config/monica.php' => $this->app->configPath('monica.php')],
            'monica-config'
        );
    }
}
