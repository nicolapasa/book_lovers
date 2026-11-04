<?php

namespace App\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookIdentifier;
use App\Models\Genre;
use App\Models\Publisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class GoogleBooks
{
    /** @return array<string, mixed> */
    private function get(string $path, array $query = []): array
    {
        if ($key = config('services.google_books.key')) {
            $query['key'] = $key;
        }
        $response = Http::acceptJson()->connectTimeout(3)->timeout(8)->get('https://www.googleapis.com/books/v1/'.$path, $query);
        if (! $response->successful()) {
            throw new RuntimeException('google_books_unavailable');
        }

        return $response->json() ?? [];
    }

    public function lookupIsbn(string $isbn, BookImporter $normalizer): Book
    {
        $results = $this->get('volumes', ['q' => 'isbn:'.$isbn, 'maxResults' => 10]);
        foreach ($results['items'] ?? [] as $volume) {
            $identifiers = $this->identifiers($volume, $normalizer);
            if (collect($identifiers)->contains(fn (array $id): bool => $id['scheme'] === 'isbn13' && $id['value'] === $isbn)) {
                return $this->save($volume, $identifiers);
            }
        }

        throw new RuntimeException('book_not_found');
    }

    /** @return array<int, array<string, mixed>> */
    public function search(string $title, ?string $author, string $locale): array
    {
        $query = 'intitle:'.str_replace('"', '', $title);
        if ($author) {
            $query .= ' inauthor:'.str_replace('"', '', $author);
        }
        $results = $this->get('volumes', ['q' => $query, 'maxResults' => 20, 'langRestrict' => $locale, 'printType' => 'books']);

        return collect($results['items'] ?? [])->filter(fn (array $volume): bool => isset($volume['id'], $volume['volumeInfo']['title']))->map(fn (array $volume): array => [
            'id' => $volume['id'], 'title' => $volume['volumeInfo']['title'],
            'author' => implode(', ', $volume['volumeInfo']['authors'] ?? []),
            'publisher' => $volume['volumeInfo']['publisher'] ?? null,
            'date' => $volume['volumeInfo']['publishedDate'] ?? null,
            'language' => $volume['volumeInfo']['language'] ?? null,
            'identifiers' => collect($volume['volumeInfo']['industryIdentifiers'] ?? [])->pluck('identifier')->all(),
        ])->values()->all();
    }

    public function importVolume(string $volumeId, BookImporter $normalizer): Book
    {
        $volume = $this->get('volumes/'.rawurlencode($volumeId));

        return $this->save($volume, $this->identifiers($volume, $normalizer));
    }

    /** @return array<int, array{scheme: string, value: string}> */
    private function identifiers(array $volume, BookImporter $normalizer): array
    {
        $ids = [];
        if (isset($volume['id']) && preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $volume['id'])) {
            $ids['google_books:'.$volume['id']] = ['scheme' => 'google_books', 'value' => $volume['id']];
        }
        foreach ($volume['volumeInfo']['industryIdentifiers'] ?? [] as $identifier) {
            if (in_array($identifier['type'] ?? '', ['ISBN_10', 'ISBN_13'], true)) {
                try {
                    $id = $normalizer->identifier((string) ($identifier['identifier'] ?? ''));
                    $ids[$id['scheme'].':'.$id['value']] = ['scheme' => $id['scheme'], 'value' => $id['value']];
                } catch (ValidationException) {
                    continue;
                }
            }
        }

        return array_values($ids);
    }

    private function save(array $volume, array $identifiers): Book
    {
        $info = $volume['volumeInfo'] ?? [];
        if (empty($info['title']) || empty($identifiers)) {
            throw new RuntimeException('book_not_found');
        }
        foreach ($identifiers as $identifier) {
            if ($known = BookIdentifier::query()->where($identifier)->first()) {
                return $known->book;
            }
        }

        try {
            return DB::transaction(function () use ($info, $identifiers): Book {
                $publisher = ! empty($info['publisher']) ? Publisher::query()->firstOrCreate(['name' => Str::limit($info['publisher'], 255, '')]) : null;
                $cover = str_replace('http://', 'https://', $info['imageLinks']['thumbnail'] ?? '');
                $book = Book::query()->create([
                    'title' => Str::limit($info['title'], 255, ''), 'subtitle' => $info['subtitle'] ?? null,
                    'publisher_id' => $publisher?->id, 'page_count' => ! empty($info['pageCount']) ? min(max((int) $info['pageCount'], 1), 1000000) : null,
                    'synopsis' => isset($info['description']) ? Str::limit(strip_tags($info['description']), 20000, '') : null,
                    'language_code' => Str::limit($info['language'] ?? '', 10, ''),
                    'cover_url' => preg_match('~^https://(?:books\.google\.com|books\.googleusercontent\.com)/~', $cover) ? $cover : null,
                    'metadata_source' => 'google_books', 'metadata_fetched_at' => now(),
                ]);
                foreach ($identifiers as $identifier) {
                    $book->identifiers()->create($identifier);
                }
                foreach (array_slice($info['authors'] ?? [], 0, 20) as $position => $name) {
                    $author = Author::query()->firstOrCreate(['name' => Str::limit($name, 255, '')]);
                    $book->authors()->syncWithoutDetaching([$author->id => ['position' => $position]]);
                }
                foreach (array_slice($info['categories'] ?? [], 0, 8) as $name) {
                    $name = Str::limit($name, 255, '');
                    $slug = Str::limit(Str::slug($name), 180, '') ?: hash('sha256', $name);
                    $genre = Genre::query()->firstOrCreate(['slug' => $slug], ['name_it' => $name, 'name_en' => $name]);
                    $book->genres()->syncWithoutDetaching($genre->id);
                }

                return $book;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $exception) {
            foreach ($identifiers as $identifier) {
                if ($known = BookIdentifier::query()->where($identifier)->first()) {
                    return $known->book;
                }
            }
            throw $exception;
        }
    }
}
