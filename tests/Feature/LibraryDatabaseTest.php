<?php

namespace Tests\Feature;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookIdentifier;
use App\Models\Genre;
use App\Models\Library;
use App\Models\LibraryBook;
use App\Models\Publisher;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\GenreSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LibraryDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private function library(?User $owner = null): Library
    {
        return ($owner ?? User::factory()->create())->libraries()->create([
            'name' => 'My library',
            'slug' => fake()->unique()->slug(),
        ]);
    }

    private function entry(?Library $library = null): LibraryBook
    {
        return ($library ?? $this->library())->libraryBooks()->create([
            'book_id' => Book::query()->create(['title' => 'A story', 'page_count' => 200])->id,
        ]);
    }

    public function test_a_catalog_book_can_belong_to_multiple_libraries_with_independent_progress(): void
    {
        $entry = $this->entry();
        $other = $this->library()->libraryBooks()->create(['book_id' => $entry->book_id, 'progress' => 60]);

        $this->assertSame(1, Book::query()->count());
        $this->assertSame(0, $entry->fresh()->progress);
        $this->assertSame(60, $other->progress);
        $this->assertSame('reading', $other->readingStatus());
    }

    public function test_the_same_book_cannot_be_added_twice_to_a_library(): void
    {
        $entry = $this->entry();
        $this->expectException(QueryException::class);
        $entry->library->libraryBooks()->create(['book_id' => $entry->book_id]);
    }

    public function test_isbn_identifiers_are_normalized_and_can_store_multiple_codes_for_one_edition(): void
    {
        $book = Book::query()->create(['title' => '1984']);
        $book->identifiers()->create(['scheme' => 'ISBN13', 'value' => '978-0-451-52493-5']);
        $book->identifiers()->create(['scheme' => 'isbn10', 'value' => '0 451 52493 4']);

        $this->assertDatabaseHas('book_identifiers', ['scheme' => 'isbn13', 'value' => '9780451524935']);
        $this->assertSame(2, $book->identifiers()->count());
    }

    public function test_a_catalog_identifier_cannot_point_to_two_different_books(): void
    {
        $one = Book::query()->create(['title' => 'One']);
        $two = Book::query()->create(['title' => 'Two']);
        $one->identifiers()->create(['scheme' => 'isbn13', 'value' => '9780451524935']);

        $this->expectException(QueryException::class);
        $two->identifiers()->create(['scheme' => 'isbn13', 'value' => '978-0-451-52493-5']);
    }

    public function test_authors_genres_and_publisher_are_related_to_the_edition(): void
    {
        $publisher = Publisher::query()->create(['name' => 'A publisher']);
        $book = $publisher->books()->create(['title' => 'A collaboration']);
        $first = Author::query()->create(['name' => 'First author']);
        $second = Author::query()->create(['name' => 'Second author']);
        $genre = Genre::query()->create(['slug' => 'fiction', 'name_it' => 'Narrativa', 'name_en' => 'Fiction']);
        $book->authors()->attach($second, ['position' => 2]);
        $book->authors()->attach($first, ['position' => 1]);
        $book->genres()->attach($genre);

        $this->assertSame([$first->id, $second->id], $book->authors->modelKeys());
        $this->assertTrue($book->publisher->is($publisher));
        $this->assertSame('Narrativa', $book->genres->first()->localizedName('it'));
        $this->assertSame('Fiction', $genre->localizedName('en'));
    }

    public function test_completion_and_reopening_update_the_reading_dates(): void
    {
        $entry = $this->entry();
        $entry->update(['progress' => 100]);
        $this->assertSame('read', $entry->readingStatus());
        $this->assertNotNull($entry->started_at);
        $this->assertNotNull($entry->finished_at);
        $finished = $entry->finished_at->toDateTimeString();
        $this->travel(1)->day();
        $entry->save();
        $this->assertSame($finished, $entry->finished_at->toDateTimeString());
        $entry->update(['progress' => 25]);
        $this->assertNull($entry->fresh()->finished_at);
        $this->assertSame('reading', $entry->readingStatus());
    }

    #[DataProvider('invalidProgress')]
    public function test_invalid_progress_is_not_saved(int $progress): void
    {
        $entry = $this->entry();
        $this->expectException(InvalidArgumentException::class);
        $entry->update(['progress' => $progress]);
    }

    public static function invalidProgress(): array
    {
        return [[-1], [101]];
    }

    public function test_private_libraries_require_ownership_or_an_accepted_affiliation(): void
    {
        $library = $this->library();
        $friend = User::factory()->create();
        $this->assertFalse($library->isVisibleTo(null));
        $this->assertTrue($library->isVisibleTo($library->owner));
        $request = $library->affiliations()->create(['user_id' => $friend->id]);
        $this->assertFalse($library->isVisibleTo($friend));
        $this->assertFalse($library->canContribute($friend));
        $request->status = 'accepted';
        $request->responded_at = now();
        $request->save();
        $this->assertTrue($library->isVisibleTo($friend));
        $this->assertTrue($library->canContribute($friend));
        $library->is_public = true;
        $library->save();
        $this->assertTrue($library->isVisibleTo(null));
    }

    public function test_hidden_libraries_and_suspended_users_cannot_contribute(): void
    {
        $library = $this->library();
        $library->hidden_at = now();
        $library->save();
        $this->assertFalse($library->isVisibleTo(null));
        $this->assertFalse($library->canContribute($library->owner));
        $admin = User::factory()->create();
        $admin->is_admin = true;
        $admin->save();
        $this->assertTrue($library->isVisibleTo($admin));
        $admin->suspended_at = now();
        $admin->save();
        $this->assertFalse($library->isVisibleTo($admin));
    }

    public function test_review_rating_and_text_are_separate_and_likes_are_related(): void
    {
        $entry = $this->entry();
        $owner = $entry->library->owner;
        $review = new Review(['rating' => 5, 'body' => 'A wonderful story.', 'locale' => 'en']);
        $review->user()->associate($owner);
        $entry->reviews()->save($review);
        $friend = User::factory()->create();
        $review->likes()->create(['user_id' => $friend->id]);
        $entry->likes()->create(['user_id' => $friend->id]);

        $this->assertSame(5, $entry->reviews->first()->rating);
        $this->assertSame(1, $review->likes()->count());
        $this->assertSame(1, $entry->likes()->count());
        $this->assertTrue($owner->reviews->first()->is($review));
    }

    #[DataProvider('invalidRatings')]
    public function test_ratings_outside_one_to_five_are_rejected(int $rating): void
    {
        $entry = $this->entry();
        $review = new Review(['rating' => $rating]);
        $review->user()->associate($entry->library->owner);
        $this->expectException(InvalidArgumentException::class);
        $entry->reviews()->save($review);
    }

    public static function invalidRatings(): array
    {
        return [[0], [6]];
    }

    public function test_likes_cannot_be_duplicated(): void
    {
        $entry = $this->entry();
        $user = User::factory()->create();
        $entry->likes()->create(['user_id' => $user->id]);
        $this->expectException(QueryException::class);
        $entry->likes()->create(['user_id' => $user->id]);
    }

    public function test_author_ranking_counts_only_the_books_in_the_requested_library(): void
    {
        $library = $this->library();
        $author = Author::query()->create(['name' => 'Prolific author']);
        $other = Author::query()->create(['name' => 'Guest author']);
        $this->entry($library)->book->authors()->attach($author);
        $this->entry($library)->book->authors()->attach($author);
        $this->entry($library)->book->authors()->attach($other);
        $this->entry()->book->authors()->attach($other);

        $ranking = $library->authorRanking()->get();
        $this->assertSame([$author->id, $other->id], $ranking->modelKeys());
        $this->assertSame([2, 1], $ranking->pluck('books_count')->all());
    }

    public function test_deleting_one_library_preserves_the_shared_catalog_and_other_libraries(): void
    {
        $entry = $this->entry();
        $otherLibrary = $this->library();
        $otherLibrary->libraryBooks()->create(['book_id' => $entry->book_id]);
        $review = new Review(['rating' => 4]);
        $review->user()->associate($entry->library->owner);
        $entry->reviews()->save($review);
        $review->likes()->create(['user_id' => $otherLibrary->user_id]);
        $entry->library->delete();

        $this->assertDatabaseCount('books', 1);
        $this->assertDatabaseCount('library_books', 1);
        $this->assertDatabaseCount('reviews', 0);
        $this->assertDatabaseCount('review_likes', 0);
        $this->assertSame(1, $otherLibrary->libraryBooks()->count());
    }

    public function test_profile_mass_assignment_cannot_grant_administrator_permissions(): void
    {
        $user = User::factory()->create();
        $user->fill(['is_admin' => true, 'suspended_at' => now(), 'locale' => 'en'])->save();

        $this->assertFalse($user->fresh()->is_admin);
        $this->assertNull($user->fresh()->suspended_at);
        $this->assertSame('en', $user->fresh()->locale);
    }

    public function test_genre_seeding_is_bilingual_and_repeatable(): void
    {
        $this->seed(GenreSeeder::class);
        $this->seed(GenreSeeder::class);
        $this->assertDatabaseCount('genres', 10);
        $this->assertDatabaseHas('genres', ['slug' => 'fiction', 'name_it' => 'Narrativa', 'name_en' => 'Fiction']);
        $this->assertSame(0, User::query()->count());
    }

    public function test_catalog_migration_can_be_rolled_back_and_applied_again(): void
    {
        $migration = require database_path('migrations/2026_11_04_100000_create_library_catalog_tables.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('books'));
        $this->assertFalse(Schema::hasColumn('users', 'is_admin'));
        $this->assertTrue(Schema::hasTable('users'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('reviews'));
        $this->assertTrue(Schema::hasColumn('users', 'is_admin'));
    }
}
