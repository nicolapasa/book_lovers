<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[Fillable(['rating', 'body', 'locale'])]
class Review extends Model
{
    protected function casts(): array
    {
        return ['rating' => 'integer', 'hidden_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (Review $review): void {
            if ($review->rating < 1 || $review->rating > 5) {
                throw new InvalidArgumentException('A rating must be between 1 and 5.');
            }
        });
    }

    public function libraryBook(): BelongsTo
    {
        return $this->belongsTo(LibraryBook::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(ReviewLike::class);
    }
}
