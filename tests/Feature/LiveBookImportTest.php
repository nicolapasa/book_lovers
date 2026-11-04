<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('external')]
class LiveBookImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_library_can_import_an_actual_isbn(): void
    {
        if (getenv('BOOK_LOVERS_LIVE_IMPORT_TEST') !== '1') {
            $this->markTestSkipped('Enable BOOK_LOVERS_LIVE_IMPORT_TEST to contact Open Library.');
        }

        $user = User::factory()->create();
        $library = $user->libraries()->create(['name' => 'Import test', 'slug' => 'import-test']);
        $this->actingAs($user)->postJson('/app/libraries/'.$library->id.'/import', ['code' => '9780451524935'])
            ->assertOk()->assertJsonPath('added', true);
        $entry = $library->libraryBooks()->with('book.identifiers', 'book.authors')->sole();
        $this->assertNotEmpty($entry->book->title);
        $this->assertNotEmpty($entry->book->authors);
        $this->assertSame('openlibrary', $entry->book->metadata_source);
        $this->assertDatabaseHas('book_identifiers', ['scheme' => 'isbn13', 'value' => '9780451524935']);
    }
}
