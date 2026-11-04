<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Library;
use App\Models\LibraryBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LibraryApplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function library(User $user, bool $public = false): Library
    {
        return $user->libraries()->create(['name' => 'My library', 'slug' => fake()->unique()->slug(), 'is_public' => $public]);
    }

    private function entry(Library $library): LibraryBook
    {
        return $library->libraryBooks()->create(['book_id' => Book::query()->create(['title' => 'A story'])->id]);
    }

    private function fakeBook(): void
    {
        Http::fake([
            'openlibrary.org/api/books*' => Http::response(['ISBN:9780451524935' => [
                'title' => '1984', 'url' => 'https://openlibrary.org/books/OL123M/1984',
                'authors' => [['name' => 'George Orwell']], 'publishers' => [['name' => 'Signet']],
                'number_of_pages' => 328, 'subjects' => [['name' => 'Fiction']],
                'cover' => ['large' => 'https://covers.openlibrary.org/b/id/123-L.jpg'],
            ]]),
            'openlibrary.org/books/OL123M.json' => Http::response(['works' => [['key' => '/works/OL123W']], 'languages' => [['key' => '/languages/eng']]]),
            'openlibrary.org/works/OL123W.json' => Http::response(['description' => ['value' => 'A dystopian novel.']]),
        ]);
    }

    public function test_registration_creates_a_private_library_and_authenticates_without_admin_privileges(): void
    {
        $this->postJson('/app/register', ['name' => 'Reader', 'email' => ' READER@example.com ', 'password' => 'a-long-test-password', 'password_confirmation' => 'a-long-test-password', 'locale' => 'en', 'is_admin' => true])
            ->assertCreated()->assertJsonStructure(['csrf']);

        $user = User::query()->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('a-long-test-password', $user->password));
        $this->assertFalse($user->is_admin);
        $this->assertSame('reader@example.com', $user->email);
        $this->assertSame('My library', $user->libraries()->sole()->name);
        $this->assertFalse($user->libraries()->sole()->is_public);
    }

    public function test_registration_requires_matching_strong_passwords_and_unique_email(): void
    {
        User::factory()->create(['email' => 'reader@example.com']);
        $this->postJson('/app/register', ['name' => 'Reader', 'email' => 'reader@example.com', 'password' => 'short', 'password_confirmation' => 'different', 'locale' => 'en'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        $this->assertDatabaseCount('users', 1);
        $this->assertGuest();
    }

    public function test_login_logout_and_wrong_password(): void
    {
        $user = User::factory()->create(['password' => 'a-long-test-password']);
        $this->postJson('/app/login', ['email' => $user->email, 'password' => 'incorrect'])->assertUnprocessable();
        $this->assertGuest();
        $this->postJson('/app/login', ['email' => $user->email, 'password' => 'a-long-test-password'])->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->postJson('/app/logout')->assertOk()->assertJsonStructure(['csrf']);
        $this->assertGuest();
    }

    public function test_suspended_accounts_cannot_sign_in_or_use_an_existing_session(): void
    {
        $user = User::factory()->create(['password' => 'a-long-test-password']);
        $user->suspended_at = now();
        $user->save();
        $this->postJson('/app/login', ['email' => $user->email, 'password' => 'a-long-test-password'])->assertUnprocessable();
        $this->actingAs($user)->postJson('/app/libraries', ['name' => 'Test'])->assertForbidden();
        $this->getJson('/app/state')->assertForbidden();
    }

    public function test_state_is_private_and_omits_passwords(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $library = $this->library($owner);
        $this->library($other);
        $this->getJson('/app/state')->assertOk()->assertJsonPath('user', null)->assertJsonCount(0, 'libraries');
        $this->actingAs($owner)->getJson('/app/state')->assertOk()->assertJsonPath('libraries.0.id', $library->id)->assertJsonCount(1, 'libraries')->assertJsonMissingPath('user.password');
    }

    public function test_guest_mutations_require_authentication(): void
    {
        $this->postJson('/app/libraries', ['name' => 'Test'])->assertUnauthorized();
        $this->getJson('/app/admin')->assertUnauthorized();
    }

    public function test_isbn_import_fetches_real_metadata_and_reuses_the_catalog_for_another_user(): void
    {
        $this->fakeBook();
        $owner = User::factory()->create();
        $library = $this->library($owner);
        $this->actingAs($owner)->postJson('/app/libraries/'.$library->id.'/import', ['code' => '0-451-52493-4'])->assertOk()->assertJsonPath('added', true);
        $this->assertDatabaseHas('books', ['title' => '1984', 'page_count' => 328, 'synopsis' => 'A dystopian novel.', 'metadata_source' => 'openlibrary']);
        $this->assertDatabaseHas('authors', ['name' => 'George Orwell']);
        $this->assertDatabaseHas('publishers', ['name' => 'Signet']);
        $this->assertDatabaseHas('book_identifiers', ['scheme' => 'isbn13', 'value' => '9780451524935']);
        $this->getJson('/app/state')->assertOk()
            ->assertJsonPath('libraries.0.books.0.title', '1984')
            ->assertJsonPath('libraries.0.books.0.authors.0', 'George Orwell')
            ->assertJsonPath('libraries.0.books.0.synopsis', 'A dystopian novel.');
        $this->postJson('/app/libraries/'.$library->id.'/import', ['code' => '9780451524935'])->assertOk()->assertJsonPath('added', false);
        $this->postJson('/app/libraries/'.$library->id.'/import', ['code' => 'OLID:OL123M'])->assertOk()->assertJsonPath('added', false);
        $other = User::factory()->create();
        $second = $this->library($other);
        $this->actingAs($other)->postJson('/app/libraries/'.$second->id.'/import', ['code' => '9780451524935'])->assertOk()->assertJsonPath('added', true);
        $this->assertDatabaseCount('books', 1);
        $this->assertDatabaseCount('library_books', 2);
        Http::assertSentCount(3);
    }

    public function test_invalid_codes_do_not_trigger_external_calls(): void
    {
        $owner = User::factory()->create();
        $library = $this->library($owner);
        foreach (['9780451524936', '123', 'OLID:../private', 'https://example.com', '1234567890123'] as $code) {
            $this->actingAs($owner)->postJson('/app/libraries/'.$library->id.'/import', ['code' => $code])->assertUnprocessable();
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('books', 0);
    }

    public function test_unavailable_metadata_and_missing_books_do_not_create_empty_records(): void
    {
        $owner = User::factory()->create();
        $library = $this->library($owner);
        Http::fake(['openlibrary.org/*' => Http::sequence()->push([], 503)->push([]), 'www.googleapis.com/*' => Http::sequence()->push([], 503)->push([])]);
        $this->actingAs($owner)->postJson('/app/libraries/'.$library->id.'/import', ['code' => '9780451524935'])->assertStatus(503)->assertJsonPath('error', 'google_books_unavailable');
        $this->postJson('/app/libraries/'.$library->id.'/import', ['code' => '9780451524935'])->assertNotFound()->assertJsonPath('error', 'book_not_found');
        $this->assertDatabaseCount('books', 0);
    }

    public function test_missing_optional_metadata_does_not_block_import(): void
    {
        Http::fake(['openlibrary.org/*' => Http::response(['OLID:OL7353617M' => ['title' => 'A book']])]);
        $owner = User::factory()->create();
        $library = $this->library($owner);
        $this->actingAs($owner)->postJson('/app/libraries/'.$library->id.'/import', ['code' => 'OLID:OL7353617M'])->assertOk();
        $this->assertDatabaseHas('books', ['title' => 'A book', 'page_count' => null, 'synopsis' => null, 'cover_url' => null]);
    }

    public function test_other_users_cannot_import_into_a_library_or_change_its_progress(): void
    {
        $owner = User::factory()->create();
        $library = $this->library($owner);
        $entry = $this->entry($library);
        $this->actingAs(User::factory()->create())->postJson('/app/libraries/'.$library->id.'/import', ['code' => '9780451524935'])->assertForbidden();
        $this->patchJson('/app/entries/'.$entry->id.'/progress', ['progress' => 100])->assertForbidden();
        $this->patchJson('/app/libraries/'.$library->id, ['is_public' => true])->assertForbidden();
        Http::assertNothingSent();
        $this->assertSame(0, $entry->fresh()->progress);
    }

    public function test_owner_progress_is_saved_and_invalid_values_are_rejected(): void
    {
        $owner = User::factory()->create();
        $entry = $this->entry($this->library($owner));
        $this->actingAs($owner)->patchJson('/app/entries/'.$entry->id.'/progress', ['progress' => 101])->assertUnprocessable();
        $this->patchJson('/app/entries/'.$entry->id.'/progress', ['progress' => 100])->assertOk();
        $this->assertSame('read', $entry->fresh()->readingStatus());
        $this->assertNotNull($entry->fresh()->finished_at);
    }

    public function test_sharing_is_explicit_and_private_libraries_are_not_discoverable(): void
    {
        $owner = User::factory()->create();
        $library = $this->library($owner);
        $this->getJson('/app/libraries/'.$library->id)->assertNotFound();
        $this->getJson('/app/libraries')->assertOk()->assertJsonCount(0);
        $this->actingAs($owner)->patchJson('/app/libraries/'.$library->id, ['is_public' => true, 'hidden_at' => now()])->assertOk();
        $this->assertNull($library->fresh()->hidden_at);
        $this->postJson('/app/logout');
        $this->getJson('/app/libraries/'.$library->id)->assertOk()->assertJsonPath('mine', false);
        $this->getJson('/app/libraries')->assertOk()->assertJsonCount(1)->assertJsonMissingPath('0.email');
    }

    public function test_only_approved_affiliates_can_review_and_like_and_requests_are_not_self_approved(): void
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create();
        $library = $this->library($owner, true);
        $entry = $this->entry($library);
        $this->actingAs($friend)->putJson('/app/entries/'.$entry->id.'/review', ['rating' => 5, 'locale' => 'en'])->assertForbidden();
        $this->postJson('/app/libraries/'.$library->id.'/affiliations', ['status' => 'accepted'])->assertOk()->assertJsonPath('status', 'pending');
        $affiliation = $library->affiliations()->sole();
        $this->patchJson('/app/affiliations/'.$affiliation->id, ['status' => 'accepted'])->assertForbidden();
        $this->actingAs($owner)->patchJson('/app/affiliations/'.$affiliation->id, ['status' => 'accepted'])->assertOk();
        $this->actingAs($friend)->putJson('/app/entries/'.$entry->id.'/review', ['rating' => 5, 'body' => 'A great book', 'locale' => 'en'])->assertOk();
        $this->putJson('/app/entries/'.$entry->id.'/review', ['rating' => 4, 'body' => 'Updated opinion', 'locale' => 'en'])->assertOk();
        $this->assertDatabaseCount('reviews', 1);
        $this->putJson('/app/entries/'.$entry->id.'/like', ['liked' => true])->assertOk();
        $this->putJson('/app/entries/'.$entry->id.'/like', ['liked' => true])->assertOk();
        $this->assertDatabaseCount('book_likes', 1);
        $review = $entry->reviews()->sole();
        $this->putJson('/app/reviews/'.$review->id.'/like', ['liked' => true])->assertOk();
        $this->putJson('/app/reviews/'.$review->id.'/like', ['liked' => false])->assertOk();
        $this->assertDatabaseCount('review_likes', 0);
    }

    public function test_admin_routes_are_protected_and_moderation_hides_a_public_library(): void
    {
        $owner = User::factory()->create();
        $library = $this->library($owner, true);
        $this->actingAs($owner)->getJson('/app/admin')->assertForbidden();
        $this->patchJson('/app/admin/libraries/'.$library->id, ['hidden' => true])->assertForbidden();
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();
        $this->actingAs($admin)->getJson('/app/admin')->assertOk()->assertJsonMissingPath('users.data.0.password');
        $this->patchJson('/app/admin/libraries/'.$library->id, ['hidden' => true])->assertOk();
        $this->patchJson('/app/admin/users/'.$owner->id, ['suspended' => true])->assertOk();
        $this->postJson('/app/logout');
        $this->getJson('/app/libraries')->assertJsonCount(0);
        $this->getJson('/app/libraries/'.$library->id)->assertNotFound();
    }
}
