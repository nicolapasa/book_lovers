<?php

namespace App\Http\Controllers;

use App\Models\Affiliation;
use App\Models\Library;
use App\Models\LibraryBook;
use App\Models\Review;
use App\Models\ReviewLike;
use App\Models\User;
use App\Services\BookImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Illuminate\Http\Client\ConnectionException;

class LibraryController extends Controller
{
    public function state(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user?->suspended_at !== null, 403);

        return response()->json([
            'csrf' => csrf_token(),
            'user' => $user?->only(['id', 'name', 'email', 'locale', 'is_admin']),
            'libraries' => $user?->libraries()->orderBy('id')->get()->map(fn (Library $library): array => $this->serializeLibrary($library, $user))->all() ?? [],
            'affiliations' => $user?->affiliations()->with('library.owner')->get()->map(fn (Affiliation $item): array => [
                'id' => $item->id, 'library_id' => $item->library_id, 'name' => $item->library->name,
                'owner' => $item->library->owner->name, 'status' => $item->status,
            ])->all() ?? [],
            'requests' => $user === null ? [] : Affiliation::query()->whereHas('library', fn ($query) => $query->where('user_id', $user->id))
                ->where('status', 'pending')->with('user', 'library')->get()->map(fn (Affiliation $item): array => [
                    'id' => $item->id, 'name' => $item->user->name, 'library' => $item->library->name,
                ]),
        ]);
    }

    public function explore(Request $request): JsonResponse
    {
        $libraries = Library::query()->where('is_public', true)->whereNull('hidden_at')
            ->whereHas('owner', fn ($query) => $query->whereNull('suspended_at'))
            ->with('owner')->withCount('libraryBooks')->latest()->limit(50)->get();

        return response()->json($libraries->map(fn (Library $library): array => [
            'id' => $library->id, 'name' => $library->name, 'description' => $library->description,
            'owner' => $library->owner->name, 'count' => $library->library_books_count,
            'mine' => $library->user_id === $request->user()?->id,
        ]));
    }

    public function show(Request $request, Library $library): JsonResponse
    {
        abort_unless($library->isVisibleTo($request->user()), 404);

        return response()->json($this->serializeLibrary($library, $request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:2000']]);
        $library = $request->user()->libraries()->create([...$data, 'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(10))]);

        return response()->json(['id' => $library->id], 201);
    }

    public function update(Request $request, Library $library): JsonResponse
    {
        $this->owner($request, $library);
        $library->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_public' => ['sometimes', 'required', 'boolean'],
        ]));

        return response()->json(['ok' => true]);
    }

    public function import(Request $request, Library $library, BookImporter $importer): JsonResponse
    {
        $this->owner($request, $library);
        abort_if($library->hidden_at !== null, 403);
        $data = $request->validate(['code' => ['required', 'string', 'max:100']]);

        try {
            $book = $importer->import($data['code']);
        } catch (ConnectionException) {
            return response()->json(['error' => 'metadata_unavailable'], 503);
        } catch (RuntimeException $exception) {
            if (! in_array($exception->getMessage(), ['book_not_found', 'metadata_unavailable', 'google_books_unavailable'], true)) {
                throw $exception;
            }

            return response()->json(['error' => $exception->getMessage()], $exception->getMessage() === 'book_not_found' ? 404 : 503);
        }

        $entry = $library->libraryBooks()->firstOrCreate(['book_id' => $book->id]);

        return response()->json(['id' => $entry->id, 'title' => $book->title, 'added' => $entry->wasRecentlyCreated]);
    }

    public function progress(Request $request, LibraryBook $entry): JsonResponse
    {
        $this->owner($request, $entry->library);
        $entry->update($request->validate(['progress' => ['required', 'integer', 'between:0,100']]));

        return response()->json(['ok' => true]);
    }

    public function review(Request $request, LibraryBook $entry): JsonResponse
    {
        abort_unless($entry->library->canContribute($request->user()), 403);
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'body' => ['nullable', 'string', 'max:5000'], 'locale' => ['required', 'in:it,en']]);
        $review = $entry->reviews()->where('user_id', $request->user()->id)->first() ?? new Review;
        $review->fill($data);
        $review->user()->associate($request->user());
        $entry->reviews()->save($review);

        return response()->json(['ok' => true]);
    }

    public function like(Request $request, LibraryBook $entry): JsonResponse
    {
        abort_unless($entry->library->canContribute($request->user()), 403);
        $liked = $request->validate(['liked' => ['required', 'boolean']])['liked'];
        if ($liked) {
            $entry->likes()->firstOrCreate(['user_id' => $request->user()->id]);
        } else {
            $entry->likes()->where('user_id', $request->user()->id)->delete();
        }

        return response()->json(['ok' => true]);
    }

    public function reviewLike(Request $request, Review $review): JsonResponse
    {
        abort_if($review->hidden_at !== null, 404);
        abort_unless($review->libraryBook->library->canContribute($request->user()), 403);
        $liked = $request->validate(['liked' => ['required', 'boolean']])['liked'];
        if ($liked) {
            $review->likes()->firstOrCreate(['user_id' => $request->user()->id]);
        } else {
            $review->likes()->where('user_id', $request->user()->id)->delete();
        }

        return response()->json(['ok' => true]);
    }

    public function affiliate(Request $request, Library $library): JsonResponse
    {
        abort_unless($library->isVisibleTo($request->user()), 404);
        abort_if($library->user_id === $request->user()->id, 422);
        $requestRow = $library->affiliations()->firstOrCreate(['user_id' => $request->user()->id]);

        return response()->json(['status' => $requestRow->fresh()->status]);
    }

    public function respond(Request $request, Affiliation $affiliation): JsonResponse
    {
        $this->owner($request, $affiliation->library);
        $data = $request->validate(['status' => ['required', 'in:accepted,rejected,blocked']]);
        $affiliation->status = $data['status'];
        $affiliation->responded_at = now();
        $affiliation->save();

        return response()->json(['ok' => true]);
    }

    private function owner(Request $request, Library $library): void
    {
        abort_unless($request->user()->id === $library->user_id, 403);
    }

    /** @return array<string, mixed> */
    private function serializeLibrary(Library $library, ?User $user): array
    {
        $library->load(['owner', 'libraryBooks.book.authors', 'libraryBooks.book.genres', 'libraryBooks.book.publisher', 'libraryBooks.book.identifiers',
            'libraryBooks.reviews' => fn ($query) => $query->whereNull('hidden_at')->with('user')->withCount('likes'),
            'libraryBooks.likes',
        ]);
        $reviewIds = $library->libraryBooks->flatMap(fn (LibraryBook $entry) => $entry->reviews->pluck('id'));
        $likedReviews = $user === null ? [] : ReviewLike::query()->where('user_id', $user->id)->whereIn('review_id', $reviewIds)->pluck('review_id')->all();

        return [
            'id' => $library->id, 'name' => $library->name, 'description' => $library->description,
            'is_public' => $library->is_public, 'hidden' => $library->hidden_at !== null,
            'owner' => $library->owner->name, 'mine' => $library->user_id === $user?->id,
            'can_contribute' => $user !== null && $library->canContribute($user),
            'books' => $library->libraryBooks->sortByDesc('id')->map(function (LibraryBook $entry) use ($user, $likedReviews): array {
                $book = $entry->book;

                $metadata = $entry->metadata ?? [];
                $authors = $metadata['authors'] ?? $book->authors->pluck('name')->all();

                return [
                    'id' => $entry->id, 'title' => $metadata['title'] ?? $book->title, 'author' => implode(', ', $authors),
                    'authors' => $authors, 'cover' => $entry->cover_path ? '/app/entries/'.$entry->id.'/cover' : $book->cover_url,
                    'genres' => isset($metadata['genres']) ? collect($metadata['genres'])->map(fn ($name) => ['it' => $name, 'en' => $name]) : $book->genres->map(fn ($genre) => ['it' => $genre->name_it, 'en' => $genre->name_en]),
                    'publisher' => array_key_exists('publisher', $metadata) ? $metadata['publisher'] : $book->publisher?->name,
                    'pages' => array_key_exists('pages', $metadata) ? $metadata['pages'] : $book->page_count,
                    'synopsis' => array_key_exists('synopsis', $metadata) ? $metadata['synopsis'] : $book->synopsis,
                    'isbn' => $metadata['isbn'] ?? $book->identifiers->firstWhere('scheme', 'isbn13')?->value,
                    'source' => $book->metadata_source,
                    'identifiers' => $book->identifiers->map(fn ($identifier) => strtoupper($identifier->scheme).': '.$identifier->value),
                    'progress' => $entry->progress, 'rating' => $entry->reviews->avg('rating'),
                    'likes' => $entry->likes->count(), 'liked' => $user !== null && $entry->likes->contains('user_id', $user->id),
                    'reviews' => $entry->reviews->map(fn (Review $review): array => [
                        'id' => $review->id, 'name' => $review->user->name, 'mine' => $review->user_id === $user?->id,
                        'rating' => $review->rating, 'body' => $review->body, 'likes' => $review->likes_count,
                        'liked' => in_array($review->id, $likedReviews, true),
                    ])->values(),
                ];
            })->values(),
        ];
    }
}
