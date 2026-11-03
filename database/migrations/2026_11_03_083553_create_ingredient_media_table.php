<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->index()->constrained('media')->restrictOnDelete();
            $table->foreignId('thumbnail_media_id')->nullable()->index()->constrained('media')->restrictOnDelete();
            $table->string('role', 20)->default('gallery');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('rights_basis', 30);
            $table->text('rights_reference');
            $table->foreignId('rights_confirmed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('rights_confirmed_at');
            $table->timestamps();
            $table->unique(['ingredient_id', 'media_id']);
            $table->index(['ingredient_id', 'sort_order']);
        });
        DB::statement("CREATE UNIQUE INDEX ingredient_media_primary_unique ON ingredient_media (ingredient_id) WHERE role = 'primary'");
        DB::statement("ALTER TABLE ingredient_media ADD CONSTRAINT ingredient_media_role_check CHECK (role IN ('primary', 'gallery'))");
        DB::statement("ALTER TABLE ingredient_media ADD CONSTRAINT ingredient_media_rights_basis_check CHECK (rights_basis IN ('own_work', 'licensed', 'permission', 'public_domain'))");
        DB::statement('ALTER TABLE ingredient_media ADD CONSTRAINT ingredient_media_rights_reference_check CHECK (length(btrim(rights_reference)) > 0)');
        DB::statement('ALTER TABLE ingredient_media ADD CONSTRAINT ingredient_media_sort_order_check CHECK (sort_order >= 0)');
        DB::statement('ALTER TABLE ingredient_media ADD CONSTRAINT ingredient_media_thumbnail_check CHECK (thumbnail_media_id IS NULL OR thumbnail_media_id <> media_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_media');
    }
};
