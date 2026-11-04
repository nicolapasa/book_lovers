<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['slug', 'name_it', 'name_en'])]
class Genre extends Model
{
    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class);
    }

    public function localizedName(string $locale): string
    {
        return $locale === 'en' ? $this->name_en : $this->name_it;
    }
}
