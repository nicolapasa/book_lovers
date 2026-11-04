<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

#[Fillable(['book_id', 'progress'])]
class LibraryBook extends Model
{
    protected function casts(): array
    {
        return ['progress' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (LibraryBook $entry): void {
            $progress = $entry->progress ?? 0;

            if ($progress < 0 || $progress > 100) {
                throw new InvalidArgumentException('Reading progress must be between 0 and 100.');
            }

            if ($progress > 0) {
                $entry->started_at ??= now();
            }

            $entry->finished_at = $progress === 100 ? ($entry->finished_at ?? now()) : null;
        });
    }

    public function readingStatus(): string
    {
        return match (true) {
            $this->progress === 100 => 'read',
            $this->progress > 0 => 'reading',
            default => 'unread',
        };
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(BookLike::class);
    }
}
