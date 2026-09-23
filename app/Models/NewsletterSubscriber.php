<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class NewsletterSubscriber extends Model
{
    protected $fillable = ['email', 'source', 'ip_address', 'subscribed_at', 'unsubscribed_at'];

    protected function casts(): array
    {
        return [
            'subscribed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    public function isSubscribed(): bool
    {
        return $this->unsubscribed_at === null;
    }

    public function scopeSubscribed(Builder $query): void
    {
        $query->whereNull('unsubscribed_at');
    }

    public function scopeUnsubscribed(Builder $query): void
    {
        $query->whereNotNull('unsubscribed_at');
    }

    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where('email', 'like', '%' . $term . '%');
    }

    /** Takes the address off the list but keeps the record, so the history stays. */
    public function unsubscribe(): void
    {
        $this->forceFill(['unsubscribed_at' => now()])->save();
    }

    public function resubscribe(): void
    {
        $this->forceFill(['unsubscribed_at' => null, 'subscribed_at' => now()])->save();
    }
}
