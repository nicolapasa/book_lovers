<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->enum('locale', ['it', 'en'])->default('it');
            $table->text('bio')->nullable();
            $table->text('avatar_url')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->timestamp('suspended_at')->nullable()->index();
        });

        Schema::create('publishers', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->index();
            $table->timestamps();
        });

        Schema::create('authors', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->index();
            $table->timestamps();
        });

        Schema::create('genres', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name_it');
            $table->string('name_en');
            $table->timestamps();
        });

        Schema::create('books', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('publisher_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->index();
            $table->text('subtitle')->nullable();
            $table->text('cover_url')->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->text('synopsis')->nullable();
            $table->string('language_code', 10)->nullable();
            $table->unsignedSmallInteger('publication_year')->nullable();
            $table->string('metadata_source')->nullable();
            $table->timestamp('metadata_fetched_at')->nullable();
            $table->timestamps();
        });

        Schema::create('book_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->string('scheme', 32);
            $table->string('value', 191);
            $table->unique(['scheme', 'value']);
            $table->timestamps();
        });

        Schema::create('author_book', function (Blueprint $table): void {
            $table->foreignId('author_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->primary(['author_id', 'book_id']);
        });

        Schema::create('book_genre', function (Blueprint $table): void {
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('genre_id')->constrained()->cascadeOnDelete();
            $table->primary(['book_id', 'genre_id']);
        });

        Schema::create('libraries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false)->index();
            $table->timestamp('hidden_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('library_books', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['library_id', 'book_id']);
            $table->index(['library_id', 'progress']);
        });

        Schema::create('affiliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['pending', 'accepted', 'rejected', 'blocked'])->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['library_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('library_book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('body')->nullable();
            $table->enum('locale', ['it', 'en'])->default('it');
            $table->timestamp('hidden_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['library_book_id', 'user_id']);
        });

        Schema::create('book_likes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('library_book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['library_book_id', 'user_id']);
        });

        Schema::create('review_likes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['review_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach (['review_likes', 'book_likes', 'reviews', 'affiliations', 'library_books', 'libraries', 'book_genre', 'author_book', 'book_identifiers', 'books', 'genres', 'authors', 'publishers'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['suspended_at']);
            $table->dropColumn(['locale', 'bio', 'avatar_url', 'is_admin', 'suspended_at']);
        });
    }
};
