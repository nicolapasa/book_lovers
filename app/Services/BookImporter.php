<?php

namespace App\Services;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookIdentifier;
use App\Models\Genre;
use App\Models\Publisher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BookImporter
{
    /** @return array{scheme: string, value: string, key: string} */
    public function identifier(string $raw): array
    {
        $code = strtoupper(preg_replace('/[\s-]+/', '', trim($raw)));

        if (preg_match('/^(OLID|OCLC|LCCN):([A-Z0-9]+)$/', $code, $matches)) {
            $valid = match ($matches[1]) {
                'OLID' => (bool) preg_match('/^OL\d+M$/', $matches[2]),
                default => (bool) preg_match('/^\d{1,20}$/', $matches[2]),
            };
            if ($valid) {
                return ['scheme' => strtolower($matches[1]), 'value' => $matches[2], 'key' => $code];
            }
        }

        $code = preg_replace('/^ISBN(?:10|13)?:/', '', $code);
        $valid = false;
        if (preg_match('/^\d{9}[\dX]$/', $code)) {
            $sum = 0;
            foreach (str_split($code) as $i => $digit) {
                $sum += ($digit === 'X' ? 10 : (int) $digit) * (10 - $i);
            }
            $valid = $sum % 11 === 0;
            $code = '978'.substr($code, 0, 9);
            $code .= $this->checkDigit($code);
        } elseif (preg_match('/^97[89]\d{10}$/', $code)) {
            $valid = $this->checkDigit(substr($code, 0, 12)) === (int) $code[12];
        }

        if (! $valid) {
            throw ValidationException::withMessages(['code' => [app()->getLocale() === 'it' ? 'ISBN o identificatore non valido.' : 'Invalid ISBN or identifier.']]);
        }

        return ['scheme' => 'isbn13', 'value' => $code, 'key' => 'ISBN:'.$code];
    }

    private function checkDigit(string $prefix): int
    {
        $sum = 0;
        foreach (str_split($prefix) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10;
    }

    public function import(string $raw): Book
    {
        $id = $this->identifier($raw);
        try {
            return $this->importOpenLibrary($raw);
        } catch (ConnectionException|RuntimeException $exception) {
            if ($id['scheme'] !== 'isbn13' || ($exception instanceof RuntimeException && ! in_array($exception->getMessage(), ['metadata_unavailable', 'book_not_found'], true))) {
                throw $exception;
            }

            return app(GoogleBooks::class)->lookupIsbn($id['value'], $this);
        }
    }

    private function importOpenLibrary(string $raw): Book
    {
        $id = $this->identifier($raw);
        $existing = BookIdentifier::query()->where('scheme', $id['scheme'])->where('value', $id['value'])->first();
        if ($existing !== null) {
            return $existing->book;
        }

        $response = Http::acceptJson()->withHeaders(['User-Agent' => 'BookLovers/1.0 (personal library manager)'])->connectTimeout(3)->timeout(7)
            ->get('https://openlibrary.org/api/books', ['bibkeys' => $id['key'], 'format' => 'json', 'jscmd' => 'data']);

        if (! $response->successful()) {
            throw new RuntimeException('metadata_unavailable');
        }
        $data = $response->json()[$id['key']] ?? null;
        if (! is_array($data) || empty($data['title'])) {
            throw new RuntimeException('book_not_found');
        }

        $identifiers = [$id['scheme'].':'.$id['value'] => ['scheme' => $id['scheme'], 'value' => $id['value']]];
        if (preg_match('~^https?://openlibrary\.org/books/(OL\d+M)(?:/|$)~', (string) ($data['url'] ?? ''), $editionMatch)) {
            $identifiers['olid:'.$editionMatch[1]] = ['scheme' => 'olid', 'value' => $editionMatch[1]];
        }
        foreach (['isbn_10', 'isbn_13'] as $scheme) {
            foreach (array_slice($data['identifiers'][$scheme] ?? [], 0, 10) as $alias) {
                if (! is_string($alias)) {
                    continue;
                }
                try {
                    $normalized = $this->identifier($alias);
                    $identifiers[$normalized['scheme'].':'.$normalized['value']] = ['scheme' => $normalized['scheme'], 'value' => $normalized['value']];
                } catch (ValidationException) {
                    continue;
                }
            }
        }
        foreach ($identifiers as $identifier) {
            $known = BookIdentifier::query()->where($identifier)->first();
            if ($known !== null) {
                BookIdentifier::query()->firstOrCreate(['scheme' => $id['scheme'], 'value' => $id['value']], ['book_id' => $known->book_id]);

                return $known->book;
            }
        }

        $synopsis = null;
        $edition = null;
        if (preg_match('~^https?://openlibrary\.org/books/(OL\d+M)(?:/|$)~', (string) ($data['url'] ?? ''), $match)) {
            try {
                $editionResponse = Http::acceptJson()->connectTimeout(3)->timeout(5)->get('https://openlibrary.org/books/'.$match[1].'.json');
                $edition = $editionResponse->successful() ? $editionResponse->json() : null;
                $description = $edition['description'] ?? null;
                $synopsis = is_array($description) ? ($description['value'] ?? null) : $description;
                if (! $synopsis && preg_match('~^/works/OL\d+W$~', $edition['works'][0]['key'] ?? '')) {
                    $workResponse = Http::acceptJson()->connectTimeout(3)->timeout(5)->get('https://openlibrary.org'.$edition['works'][0]['key'].'.json');
                    $description = $workResponse->successful() ? $workResponse->json('description') : null;
                    $synopsis = is_array($description) ? ($description['value'] ?? null) : $description;
                }
            } catch (ConnectionException) {
                $synopsis = null;
            }
        }

        try {
            return DB::transaction(function () use ($identifiers, $data, $edition, $synopsis): Book {
                $publisherName = data_get($data, 'publishers.0.name');
                $publisher = is_string($publisherName) ? Publisher::query()->firstOrCreate(['name' => Str::limit($publisherName, 255, '')]) : null;
                $cover = data_get($data, 'cover.large', data_get($data, 'cover.medium'));
                $book = Book::query()->create([
                    'title' => Str::limit((string) $data['title'], 255, ''),
                    'subtitle' => is_string($data['subtitle'] ?? null) ? $data['subtitle'] : null,
                    'publisher_id' => $publisher?->id,
                    'cover_url' => is_string($cover) && preg_match('~^https://covers\.openlibrary\.org/~', $cover) ? $cover : null,
                    'page_count' => is_numeric($data['number_of_pages'] ?? null) && $data['number_of_pages'] > 0 ? min((int) $data['number_of_pages'], 1000000) : null,
                    'synopsis' => is_string($synopsis) ? Str::limit(strip_tags($synopsis), 20000, '') : null,
                    'language_code' => isset($edition['languages'][0]['key']) ? substr($edition['languages'][0]['key'], -3) : null,
                    'metadata_source' => 'openlibrary',
                    'metadata_fetched_at' => now(),
                ]);
                foreach ($identifiers as $identifier) {
                    $book->identifiers()->create($identifier);
                }
                foreach (array_slice($data['authors'] ?? [], 0, 20) as $position => $authorData) {
                    if (is_string($authorData['name'] ?? null)) {
                        $author = Author::query()->firstOrCreate(['name' => Str::limit($authorData['name'], 255, '')]);
                        $book->authors()->syncWithoutDetaching([$author->id => ['position' => $position]]);
                    }
                }
                foreach (array_slice($data['subjects'] ?? [], 0, 8) as $subject) {
                    if (is_string($subject['name'] ?? null) && trim($subject['name']) !== '') {
                        $name = Str::limit($subject['name'], 255, '');
                        $slug = Str::limit(Str::slug($name), 180, '') ?: 'subject-'.substr(hash('sha256', $name), 0, 16);
                        $genre = Genre::query()->firstOrCreate(['slug' => $slug], ['name_it' => $name, 'name_en' => $name]);
                        $book->genres()->syncWithoutDetaching($genre->id);
                    }
                }

                return $book;
            });
        } catch (UniqueConstraintViolationException $exception) {
            foreach ($identifiers as $identifier) {
                $known = BookIdentifier::query()->where($identifier)->first();
                if ($known !== null) {
                    return $known->book;
                }
            }

            throw $exception;
        }
    }
}
