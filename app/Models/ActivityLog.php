<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    /** Log entries are never edited, so there is no updated_at. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'module', 'action', 'subject_type', 'subject_id',
        'description', 'old_values', 'new_values', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subject()
    {
        return $this->morphTo();
    }

    public function getActionLabelAttribute(): string
    {
        return ucfirst(str_replace('_', ' ', $this->action));
    }
}
