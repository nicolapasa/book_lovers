<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class SubdirectoryDeploymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_assets_navigation_and_private_covers_keep_the_deployment_prefix(): void
    {
        URL::forceRootUrl('https://sviluppodev.eu/book-lovers');
        URL::forceScheme('https');
        $this->get('http://localhost/')->assertOk()
            ->assertSee('https://sviluppodev.eu/book-lovers/css/book-lovers.css', false)
            ->assertSee('https://sviluppodev.eu/book-lovers/js/library-app.js', false)
            ->assertSee('name="app-url" content="https://sviluppodev.eu/book-lovers"', false)
            ->assertSee('href="https://sviluppodev.eu/book-lovers"', false);

        $user = User::factory()->create();
        $library = $user->libraries()->create(['name' => 'Library', 'slug' => 'library']);
        $entry = $library->libraryBooks()->create(['book_id' => Book::query()->create(['title' => 'Book'])->id]);
        $entry->cover_path = 'book-covers/test.png';
        $entry->save();
        $this->actingAs($user)->getJson('http://localhost/app/state')->assertOk()
            ->assertJsonPath('libraries.0.books.0.cover', 'https://sviluppodev.eu/book-lovers/app/entries/'.$entry->id.'/cover');
        $dialog = $this->getJson('http://localhost/app/dialog?kind=detail&entry='.$entry->id)->assertOk();
        $this->assertStringContainsString('https://sviluppodev.eu/book-lovers/app/entries/'.$entry->id.'/cover', $dialog->json('html'));
    }
}
