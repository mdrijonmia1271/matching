<?php

namespace App\Support;

use App\Services\SettingsRepository;

/** Static shortcut to the store settings, usable from Blade: Settings::get('store_name'). */
final class Settings
{
    public static function get(string $key, mixed $default = null): mixed
    {
        return app(SettingsRepository::class)->get($key, $default);
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        return app(SettingsRepository::class)->all();
    }
}
