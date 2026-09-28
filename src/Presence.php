<?php

declare(strict_types=1);

namespace Monica;

/**
 * When the next `client_report` heartbeat is due (payload.md「client_report と
 * 稼働確認」).
 *
 * php-fpm starts every request from nothing, so what has to outlive the request
 * lives outside the process: in APCu when it is enabled, otherwise in a file
 * under `sys_get_temp_dir()`. The key is derived from the DSN, so two projects
 * on one host do not silence each other. Nothing is locked: two workers that
 * decide at the same instant send two heartbeats, which MONICA folds into one.
 *
 * @internal
 */
final class Presence
{
    public const INTERVAL_MS = 86400000;
    public const MIN_INTERVAL_MS = 60000;
    // transport.json's sample rate is for distributed clients. A server SDK
    // reports every process, so these are pinned by the contract test only.
    public const SAMPLE_RATE = 1;
    public const MIN_SAMPLE_RATE = 0.01;
    public const INTERVAL_HEADER = 'X-Monica-Presence-Interval-Ms';
    public const SAMPLE_RATE_HEADER = 'X-Monica-Presence-Sample-Rate';

    private string $key;
    /** @var callable(): (int|float) milliseconds since the epoch */
    private $clock;

    public function __construct(string $dsn, callable $clock)
    {
        $this->key = 'monica-presence-' . sha1($dsn);
        $this->clock = $clock;
    }

    /**
     * `start` when this host has no record yet, `interval` when the interval
     * has passed since the last record, null otherwise.
     */
    public function due(): ?string
    {
        $state = $this->read();
        if ($state === null) {
            return 'start';
        }

        return $this->now() - $state['at'] >= $state['interval_ms'] ? 'interval' : null;
    }

    /**
     * Start the interval over from now: an envelope was accepted, or a
     * heartbeat failed and is given up until the next interval. A valid
     * interval header replaces the stored interval; a missing or broken one
     * keeps it.
     */
    public function record(?string $intervalHeader): void
    {
        $state = $this->read();
        $interval = self::parseInterval($intervalHeader) ?? ($state['interval_ms'] ?? self::INTERVAL_MS);
        $this->write(['at' => $this->now(), 'interval_ms' => $interval]);
    }

    /**
     * The header as a decimal integer of at least MIN_INTERVAL_MS, or null.
     */
    public static function parseInterval(?string $header): ?int
    {
        $value = $header === null ? '' : trim($header);
        if (preg_match('/^[0-9]{1,15}$/', $value) !== 1) {
            return null;
        }
        $interval = (int) $value;

        return $interval >= self::MIN_INTERVAL_MS ? $interval : null;
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }

    /**
     * @return array{at: int, interval_ms: int}|null
     */
    private function read(): ?array
    {
        if (self::apcu()) {
            $state = apcu_fetch($this->key);
        } else {
            $json = @file_get_contents($this->file());
            $state = $json === false ? null : json_decode($json, true);
        }
        if (!is_array($state) || !is_int($state['at'] ?? null) || !is_int($state['interval_ms'] ?? null)) {
            return null;
        }
        $state['interval_ms'] = max(self::MIN_INTERVAL_MS, $state['interval_ms']);

        return $state;
    }

    /**
     * @param array{at: int, interval_ms: int} $state
     */
    private function write(array $state): void
    {
        if (self::apcu()) {
            apcu_store($this->key, $state);
            return;
        }
        // Failing to write only means the next request sends a heartbeat too.
        @file_put_contents($this->file(), json_encode($state), LOCK_EX);
    }

    private function file(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->key . '.json';
    }

    private static function apcu(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }
}
