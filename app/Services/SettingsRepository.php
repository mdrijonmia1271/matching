<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/**
 * Store settings: config/shop.php defaults overridden by rows in `settings`.
 *
 * Bound as a singleton, so values are read from the cache once per request.
 */
class SettingsRepository
{
    protected const CACHE_KEY = 'shop.settings';

    /** @var array<string, mixed>|null */
    protected ?array $values = null;

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        $stored = [];

        try {
            $stored = Cache::rememberForever(self::CACHE_KEY, fn () => Setting::pluck('value', 'key')->all());
        } catch (QueryException) {
            // Before migrations have run there is no settings table; fall back to defaults.
        }

        $values = [];

        foreach ($this->defaults() as $key => $default) {
            $values[$key] = array_key_exists($key, $stored) ? $this->cast($stored[$key], $default) : $default;
        }

        return $this->values = $values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * Persist known keys, ignoring unchanged values.
     *
     * @param  array<string, mixed>  $values
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [old, new] of what changed
     */
    public function set(array $values): array
    {
        $current = $this->all();
        $defaults = $this->defaults();
        $old = [];
        $new = [];

        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $defaults)) {
                continue;
            }

            $stored = match (true) {
                is_array($value) => json_encode(array_values($value)),
                is_bool($value) => $value ? '1' : '0',
                default => (string) $value,
            };

            $cast = $this->cast($stored, $defaults[$key]);

            if ($cast === $current[$key]) {
                continue;
            }

            Setting::updateOrCreate(['key' => $key], ['value' => $stored]);

            $old[$key] = $current[$key];
            $new[$key] = $cast;
        }

        Cache::forget(self::CACHE_KEY);
        $this->values = null;

        return [$old, $new];
    }

    /** @return array<string, mixed> */
    protected function defaults(): array
    {
        return config('shop.defaults', []);
    }

    protected function cast(?string $value, mixed $default): mixed
    {
        return match (true) {
            is_bool($default) => $value === '1' || $value === 'true',
            is_int($default) => (int) $value,
            is_float($default) => (float) $value,
            is_array($default) => json_decode($value ?? '[]', true) ?: [],
            default => (string) $value,
        };
    }
}
