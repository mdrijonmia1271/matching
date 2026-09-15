<?php

namespace App\Support;

/** Read-only view of the permission keys defined in config/permissions.php. */
final class Permissions
{
    /** @return array<string, array<string, string>> group label => [key => label] */
    public static function groups(): array
    {
        return config('permissions.groups', []);
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(array_merge(...array_values(self::groups())));
    }

    public static function exists(string $permission): bool
    {
        return in_array($permission, self::all(), true);
    }
}
