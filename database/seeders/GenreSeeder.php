<?php

namespace Database\Seeders;

use App\Models\Genre;
use Illuminate\Database\Seeder;

class GenreSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['fiction', 'Narrativa', 'Fiction'],
            ['classics', 'Classici', 'Classics'],
            ['fantasy', 'Fantasy', 'Fantasy'],
            ['mystery', 'Mistero', 'Mystery'],
            ['essays', 'Saggi', 'Essays'],
            ['science-fiction', 'Fantascienza', 'Science fiction'],
            ['biography', 'Biografie', 'Biography'],
            ['history', 'Storia', 'History'],
            ['poetry', 'Poesia', 'Poetry'],
            ['children', 'Bambini e ragazzi', 'Children and young adults'],
        ] as [$slug, $italian, $english]) {
            Genre::query()->updateOrCreate(['slug' => $slug], ['name_it' => $italian, 'name_en' => $english]);
        }
    }
}
