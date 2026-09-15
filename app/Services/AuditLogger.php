<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/** Writes the admin activity log. Entries are append-only. */
class AuditLogger
{
    /** Attributes that must never be written to the log. */
    protected const SECRET = ['password', 'remember_token'];

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public static function log(
        string $module,
        string $action,
        ?Model $subject = null,
        ?string $description = null,
        array $old = [],
        array $new = [],
        ?User $user = null,
    ): ActivityLog {
        $request = request();

        return ActivityLog::create([
            'user_id' => $user?->id ?? Auth::id(),
            'module' => $module,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'description' => $description ? Str::limit($description, 250) : null,
            'old_values' => Arr::except($old, self::SECRET) ?: null,
            'new_values' => Arr::except($new, self::SECRET) ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
        ]);
    }

    /**
     * The unsaved changes on a model, as [old, new]. Call after fill(), before save().
     *
     * @param  list<string>  $except
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function changes(Model $model, array $except = ['updated_at']): array
    {
        $old = [];
        $new = [];

        foreach (Arr::except($model->getDirty(), array_merge($except, self::SECRET)) as $key => $value) {
            $old[$key] = $model->getOriginal($key);
            $new[$key] = $value;
        }

        return [$old, $new];
    }
}
