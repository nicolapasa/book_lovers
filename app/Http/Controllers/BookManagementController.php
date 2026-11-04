<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\BookIdentifier;
use App\Models\Library;
use App\Models\LibraryBook;
use App\Services\BookImporter;
use App\Services\GoogleBooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class BookManagementController extends Controller
{
    public function search(Request $request, GoogleBooks $google): JsonResponse
    {
        $data = $request->validate(['title' => ['nullable', 'string', 'min:2', 'max:200'], 'author' => ['nullable', 'string', 'max:150'], 'locale' => ['required', 'in:it,en']]);
        try {
            $results = $google->search($data['title'], $data['author'] ?? null, $data['locale']);
            $t = fn (string $it, string $en): string => app()->getLocale() === 'en' ? $en : $it;

            return response()->json(['results' => $results, 'html' => view('library.partials.search-results', compact('results', 't'))->render()]);
        } catch (\Illuminate\Http\Client\ConnectionException|\RuntimeException) {
            return response()->json(['error' => 'google_books_unavailable'], 503);
        }
    }

    public function select(Request $request, Library $library, GoogleBooks $google, BookImporter $importer): JsonResponse
    {
        $this->owner($request, $library);
        $data = $request->validate(['volume_id' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]{1,100}$/']]);
        try {
            $book = $google->importVolume($data['volume_id'], $importer);
        } catch (\Illuminate\Http\Client\ConnectionException|\RuntimeException) {
            return response()->json(['error' => 'google_books_unavailable'], 503);
        }
        $entry = $library->libraryBooks()->firstOrCreate(['book_id' => $book->id]);

        return response()->json(['id' => $entry->id, 'added' => $entry->wasRecentlyCreated]);
    }

    public function store(Request $request, Library $library, BookImporter $importer): JsonResponse
    {
        $this->owner($request, $library);
        $data = $this->validated($request);
        $identifier = ! empty($data['isbn']) ? $importer->identifier($data['isbn']) : null;
        abort_if($identifier !== null && $identifier['scheme'] !== 'isbn13', 422);
        $entry = DB::transaction(function () use ($library, $data, $identifier): LibraryBook {
            $book = $identifier === null ? null : BookIdentifier::query()->where('scheme', $identifier['scheme'])->where('value', $identifier['value'])->first()?->book;
            if ($book === null) {
                $book = Book::query()->create(['title' => $data['title'], 'metadata_source' => 'manual']);
                if ($identifier !== null) {
                    $book->identifiers()->create(['scheme' => $identifier['scheme'], 'value' => $identifier['value']]);
                }
            }

            return $library->libraryBooks()->firstOrCreate(['book_id' => $book->id]);
        });
        $this->saveMetadata($request, $entry, $data);

        return response()->json(['id' => $entry->id], 201);
    }

    public function update(Request $request, LibraryBook $entry): JsonResponse
    {
        $this->owner($request, $entry->library);
        $data = $this->validated($request);
        if (! empty($data['isbn'])) {
            app(BookImporter::class)->identifier($data['isbn']);
        }
        $this->saveMetadata($request, $entry, $data);

        return response()->json(['id' => $entry->id]);
    }

    public function cover(Request $request, LibraryBook $entry): Response
    {
        abort_unless($entry->library->isVisibleTo($request->user()), 404);
        abort_unless($entry->cover_path && Storage::disk('local')->exists($entry->cover_path), 404);

        return Storage::disk('local')->response($entry->cover_path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function owner(Request $request, Library $library): void
    {
        abort_unless($request->user()->id === $library->user_id && $library->hidden_at === null, 403);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        foreach (['authors', 'genres'] as $field) {
            if ($request->exists($field.'_text')) {
                $request->merge([$field => array_values(array_filter(array_map('trim', explode(';', (string) $request->input($field.'_text')))))]);
            }
        }
        return $request->validate([
            'title' => ['required', 'string', 'max:255'], 'isbn' => ['nullable', 'string', 'max:30'],
            'authors' => ['present', 'array', 'max:20'], 'authors.*' => ['required', 'string', 'max:255'],
            'genres' => ['present', 'array', 'max:8'], 'genres.*' => ['required', 'string', 'max:100'],
            'publisher' => ['nullable', 'string', 'max:255'], 'pages' => ['nullable', 'integer', 'between:1,1000000'],
            'synopsis' => ['nullable', 'string', 'max:20000'],
            'cover' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=4096,max_height=4096'],
        ]);
    }

    private function saveMetadata(Request $request, LibraryBook $entry, array $data): void
    {
        $entry->metadata = collect($data)->except('cover')->all();
        $oldPath = $entry->cover_path;
        $newPath = null;
        if ($request->hasFile('cover')) {
            $newPath = $request->file('cover')->store('book-covers', 'local');
            abort_if($newPath === false, 500);
            $entry->cover_path = $newPath;
        }
        try {
            $entry->save();
        } catch (\Throwable $exception) {
            if ($newPath) {
                Storage::disk('local')->delete($newPath);
            }
            throw $exception;
        }
        if ($newPath && $oldPath) {
            Storage::disk('local')->delete($oldPath);
        }
    }
}
