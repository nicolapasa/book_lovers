<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['publisher_id', 'title', 'subtitle', 'cover_url', 'page_count', 'synopsis', 'language_code', 'publication_year', 'metadata_source', 'metadata_fetched_at'])]
class Book extends Model
{
    protected function casts(): array
    {
        return ['page_count' => 'integer', 'publication_year' => 'integer', 'metadata_fetched_at' => 'datetime'];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(Author::class)->withPivot('position')->orderByPivot('position');
    }

    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class);
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(BookIdentifier::class);
    }

    public function libraryBooks(): HasMany
    {
        return $this->hasMany(LibraryBook::class);
    }
}
