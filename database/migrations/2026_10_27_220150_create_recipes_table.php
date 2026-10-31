<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('category')->index();
            $table->string('excerpt', 280);
            $table->longText('description');
            $table->unsignedSmallInteger('prep_minutes');
            $table->unsignedSmallInteger('cook_minutes');
            $table->unsignedSmallInteger('servings')->default(2);
            $table->string('difficulty')->default('Easy');
            $table->string('image_url');
            $table->json('ingredients');
            $table->json('instructions');
            $table->boolean('is_featured')->default(false);
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recipes');
    }
};
