<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_books', function (Blueprint $table): void {
            $table->json('metadata')->nullable();
            $table->string('cover_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('library_books', function (Blueprint $table): void {
            $table->dropColumn(['metadata', 'cover_path']);
        });
    }
};
