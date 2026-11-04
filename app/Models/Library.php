<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'description', 'is_public'])]
class Library extends Model
{
    protected function casts(): array
    {
        return ['is_public' => 'boolean', 'hidden_at' => 'datetime'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function libraryBooks(): HasMany
    {
        return $this->hasMany(LibraryBook::class);
    }

    public function affiliations(): HasMany
    {
        return $this->hasMany(Affiliation::class);
    }

    public function isVisibleTo(?User $user): bool
    {
        if ($user?->suspended_at !== null) {
            return false;
        }

        if ($user?->is_admin) {
            return true;
        }

        if ($this->hidden_at !== null || $this->owner->suspended_at !== null) {
            return false;
        }

        return $this->is_public || $this->user_id === $user?->id || ($user !== null && $this->hasAcceptedAffiliate($user));
    }

    public function canContribute(User $user): bool
    {
        return $this->hidden_at === null
            && $this->owner->suspended_at === null
            && $user->suspended_at === null
            && ($this->user_id === $user->id || $this->hasAcceptedAffiliate($user));
    }

    public function authorRanking(): Builder
    {
        return Author::query()
            ->whereHas('books.libraryBooks', fn (Builder $query): Builder => $query->where('library_id', $this->id))
            ->withCount(['books' => fn (Builder $query): Builder => $query->whereHas('libraryBooks', fn (Builder $entries): Builder => $entries->where('library_id', $this->id))])
            ->orderByDesc('books_count')
            ->orderBy('name');
    }

    private function hasAcceptedAffiliate(User $user): bool
    {
        return $this->affiliations()->where('user_id', $user->id)->where('status', 'accepted')->exists();
    }
}
