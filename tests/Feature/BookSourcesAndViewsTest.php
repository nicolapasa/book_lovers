<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Library;
use App\Models\LibraryBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BookSourcesAndViewsTest extends TestCase
{
    use RefreshDatabase;

    private function library(User $owner): Library
    {
        return $owner->libraries()->create(['name' => 'Libreria italiana', 'slug' => fake()->unique()->slug()]);
    }

    private function volume(string $id = 'italian-edition', string $isbn = '9780451524935'): array
    {
        return ['id' => $id, 'volumeInfo' => [
            'title' => 'Un’edizione italiana', 'authors' => ['Stephen King'], 'publisher' => 'Editore italiano',
            'language' => 'it', 'pageCount' => 400, 'description' => '<p>Una sinossi italiana.</p>',
            'industryIdentifiers' => [['type' => 'ISBN_13', 'identifier' => $isbn]],
            'categories' => ['Horror'], 'publishedDate' => '2020',
            'imageLinks' => ['thumbnail' => 'http://books.google.com/books/content?id='.$id],
        ]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_google_books_is_used_when_open_library_has_no_edition(): void
    {
        Http::fake(['openlibrary.org/*' => Http::response([]), 'www.googleapis.com/books/v1/volumes*' => Http::response(['items' => [$this->volume()]])]);
        $user = User::factory()->create();
        $library = $this->library($user);
        $this->actingAs($user)->postJson('/app/libraries/'.$library->id.'/import', ['code' => '9780451524935'])->assertOk();
        $this->assertDatabaseHas('books', ['title' => 'Un’edizione italiana', 'metadata_source' => 'google_books', 'language_code' => 'it', 'synopsis' => 'Una sinossi italiana.']);
        $this->getJson('/app/state')->assertJsonPath('libraries.0.books.0.publisher', 'Editore italiano');
    }

    public function test_google_results_with_a_different_isbn_are_not_silently_imported(): void
    {
        Http::fake(['openlibrary.org/*' => Http::response([]), 'www.googleapis.com/*' => Http::response(['items' => [$this->volume('wrong-edition', '9780743273565')]])]);
        $user = User::factory()->create();
        $library = $this->library($user);
        $this->actingAs($user)->postJson('/app/libraries/'.$library->id.'/import', ['code' => '9780451524935'])->assertNotFound();
        $this->assertDatabaseCount('books', 0);
    }

    public function test_google_is_also_used_after_open_library_connection_failure(): void
    {
        Http::fake(['openlibrary.org/*' => Http::failedConnection(), 'www.googleapis.com/*' => Http::response(['items' => [$this->volume()]])]);
        $user = User::factory()->create();
        $library = $this->library($user);
        $this->actingAs($user)->postJson('/app/libraries/'.$library->id.'/import', ['code' => '9780451524935'])->assertOk();
    }

    public function test_search_returns_a_blade_fragment_and_selected_volume_is_imported(): void
    {
        Http::fake([
            'www.googleapis.com/books/v1/volumes/italian-edition*' => Http::response($this->volume()),
            'www.googleapis.com/books/v1/volumes?*' => Http::response(['items' => [$this->volume()]]),
        ]);
        $user = User::factory()->create();
        $library = $this->library($user);
        $response = $this->actingAs($user)->getJson('/app/books/search?title=It&author=Stephen%20King&locale=it')->assertOk();
        $this->assertStringContainsString('Scegli questa edizione', $response->json('html'));
        Http::assertSent(fn ($request): bool => $request['langRestrict'] === 'it' && str_contains($request['q'], 'inauthor:Stephen King'));
        $this->postJson('/app/libraries/'.$library->id.'/select', ['volume_id' => 'italian-edition'])->assertOk();
        $this->assertDatabaseCount('books', 1);
    }

    public function test_google_books_can_be_searched_by_author_without_a_title(): void
    {
        Http::fake(['www.googleapis.com/books/v1/volumes?*' => Http::response(['items' => [$this->volume()]])]);
        $this->actingAs(User::factory()->create());

        foreach (['', '&title='] as $title) {
            $this->getJson('/app/books/search?author=Stephen%20King&locale=it'.$title)
                ->assertOk()->assertJsonPath('results.0.id', 'italian-edition');
        }

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request['q'] === 'inauthor:Stephen King' && $request['langRestrict'] === 'it');
    }

    public function test_google_books_can_still_be_searched_by_title_only(): void
    {
        $volume = $this->volume('english-edition');
        $volume['volumeInfo']['language'] = 'en';
        Http::fake(['www.googleapis.com/books/v1/volumes?*' => Http::response(['items' => [$volume]])]);

        $this->actingAs(User::factory()->create())->getJson('/app/books/search?title=It&locale=en')
            ->assertOk()->assertJsonPath('results.0.id', 'english-edition');

        Http::assertSent(fn ($request): bool => $request['q'] === 'intitle:It' && $request['langRestrict'] === 'en');
    }

    public function test_google_books_search_only_returns_editions_in_the_selected_language(): void
    {
        $italian = $this->volume();
        $english = $this->volume('english-edition');
        $english['volumeInfo']['language'] = 'en';
        $unknown = $this->volume('unknown-language');
        unset($unknown['volumeInfo']['language']);
        Http::fake(['www.googleapis.com/books/v1/volumes?*' => Http::response(['items' => [$english, $unknown, $italian]])]);
        $this->actingAs(User::factory()->create());

        foreach (['it' => 'italian-edition', 'en' => 'english-edition'] as $locale => $volumeId) {
            $response = $this->getJson('/app/books/search?author=Stephen%20King&locale='.$locale)
                ->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('results.0.id', $volumeId);
            $this->assertStringNotContainsString('unknown-language', $response->json('html'));
        }
    }

    public function test_google_books_search_returns_no_results_when_no_editions_match_the_language(): void
    {
        $volume = $this->volume('english-edition');
        $volume['volumeInfo']['language'] = 'en';
        Http::fake(['www.googleapis.com/books/v1/volumes?*' => Http::response(['items' => [$volume]])]);

        $this->actingAs(User::factory()->create())->getJson('/app/books/search?author=Stephen%20King&locale=it')
            ->assertOk()->assertJsonCount(0, 'results');
    }

    public function test_google_books_search_requires_a_title_or_author(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['', '&title=&author=', '&title=%20%20&author=%20%20'] as $fields) {
            $this->getJson('/app/books/search?locale=it'.$fields)
                ->assertUnprocessable()->assertJsonValidationErrors(['title', 'author']);
        }

        Http::assertNothingSent();
    }

    public function test_manual_addition_works_without_an_isbn_or_external_services(): void
    {
        $user = User::factory()->create();
        $library = $this->library($user);
        $this->actingAs($user)->postJson('/app/libraries/'.$library->id.'/books', ['title' => 'Il mio libro', 'authors_text' => 'Stephen King; Altro autore', 'genres_text' => 'Horror', 'publisher' => 'Sperling & Kupfer', 'pages' => 400, 'synopsis' => 'Una storia.'])->assertCreated();
        $this->getJson('/app/state')->assertJsonPath('libraries.0.books.0.author', 'Stephen King, Altro autore')->assertJsonPath('libraries.0.books.0.pages', 400);
        Http::assertNothingSent();
    }

    public function test_personal_edits_do_not_modify_the_shared_catalog_or_another_library(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $book = Book::query()->create(['title' => 'Original', 'synopsis' => 'Original synopsis']);
        $entry = $this->library($owner)->libraryBooks()->create(['book_id' => $book->id]);
        $this->library($other)->libraryBooks()->create(['book_id' => $book->id]);
        $this->actingAs($owner)->postJson('/app/entries/'.$entry->id.'/metadata', ['title' => 'Titolo italiano', 'authors' => ['Un autore'], 'genres' => [], 'pages' => 123])->assertOk();
        $this->getJson('/app/state')->assertJsonPath('libraries.0.books.0.title', 'Titolo italiano');
        $this->assertSame('Original', $book->fresh()->title);
        $this->actingAs($other)->getJson('/app/state')->assertJsonPath('libraries.0.books.0.title', 'Original');
        $this->postJson('/app/entries/'.$entry->id.'/metadata', ['title' => 'Not mine', 'authors' => [], 'genres' => []])->assertForbidden();
    }

    public function test_uploaded_cover_is_private_until_library_is_shared(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $library = $this->library($user);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6EqkAAAAASUVORK5CYII=');
        $file = UploadedFile::fake()->createWithContent('cover.png', $png);
        $response = $this->actingAs($user)->post('/app/libraries/'.$library->id.'/books', ['title' => 'Copertina', 'authors_text' => '', 'genres_text' => '', 'cover' => $file], ['Accept' => 'application/json'])->assertCreated();
        $entry = LibraryBook::query()->findOrFail($response->json('id'));
        Storage::disk('local')->assertExists($entry->cover_path);
        $this->get('/app/entries/'.$entry->id.'/cover')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->postJson('/app/logout');
        $this->get('/app/entries/'.$entry->id.'/cover')->assertNotFound();
        $library->is_public = true;
        $library->save();
        $this->get('/app/entries/'.$entry->id.'/cover')->assertOk();
    }

    public function test_invalid_covers_and_metadata_are_rejected(): void
    {
        $user = User::factory()->create();
        $library = $this->library($user);
        $this->actingAs($user)->post('/app/libraries/'.$library->id.'/books', ['title' => '', 'authors' => [], 'genres' => [], 'pages' => -1, 'cover' => UploadedFile::fake()->createWithContent('cover.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors(['title', 'pages', 'cover']);
        $this->assertDatabaseCount('books', 0);
    }

    public function test_pages_and_dialogs_are_rendered_from_blade_in_both_languages(): void
    {
        $this->get('/')->assertOk()->assertSee('Tra le pagine,')->assertSee('data-action="nav"', false);
        $this->withHeader('X-Locale', 'en')->getJson('/app/view')->assertOk()->assertJsonPath('title', 'My library');
        $this->getJson('/app/dialog?kind=manual')->assertUnauthorized();
        $user = User::factory()->create();
        $library = $this->library($user);
        foreach (['library', 'explore', 'friends', 'reviews', 'stats'] as $page) {
            $response = $this->actingAs($user)->getJson('/app/view?view='.$page.'&library='.$library->id)->assertOk();
            $this->assertStringContainsString('<main', $response->json('html'));
        }
        foreach (['auth', 'login', 'profile', 'import', 'search', 'manual', 'share', 'new-library'] as $kind) {
            $response = $this->getJson('/app/dialog?kind='.$kind.'&library='.$library->id)->assertOk();
            $this->assertStringContainsString('id="modal-title"', $response->json('html'));
        }
        $entry = $library->libraryBooks()->create(['book_id' => Book::query()->create(['title' => '<script>alert(1)</script>'])->id]);
        $response = $this->getJson('/app/dialog?kind=detail&entry='.$entry->id)->assertOk();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $response->json('html'));
        $this->assertStringContainsString('&lt;script&gt;', $response->json('html'));
    }

    public function test_private_book_dialogs_and_admin_views_are_authorized(): void
    {
        $owner = User::factory()->create();
        $entry = $this->library($owner)->libraryBooks()->create(['book_id' => Book::query()->create(['title' => 'Private'])->id]);
        $this->actingAs(User::factory()->create())->getJson('/app/dialog?kind=detail&entry='.$entry->id)->assertNotFound();
        $this->getJson('/app/view?view=admin')->assertForbidden();
        $owner->is_admin = true;
        $owner->save();
        $this->actingAs($owner)->getJson('/app/view?view=admin')->assertOk();
    }
}
