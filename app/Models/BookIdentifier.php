<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['book_id', 'scheme', 'value'])]
class BookIdentifier extends Model
{
    protected static function booted(): void
    {
        static::saving(function (BookIdentifier $identifier): void {
            $identifier->scheme = strtolower(trim($identifier->scheme));
            $identifier->value = trim($identifier->value);

            if (in_array($identifier->scheme, ['isbn10', 'isbn13'], true)) {
                $identifier->value = strtoupper(preg_replace('/[\s-]+/', '', $identifier->value));
            }
        });
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
