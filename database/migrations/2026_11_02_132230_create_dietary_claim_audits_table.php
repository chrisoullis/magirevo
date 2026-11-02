<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Identifiers are plain columns, not foreign keys, so the history survives deletion of the subject.
        Schema::create('dietary_claim_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('dietary_claim_id')->nullable();
            $table->unsignedBigInteger('recipe_id');
            $table->unsignedBigInteger('tag_id');
            $table->string('action', 20);
            $table->string('previous_status', 20)->nullable();
            $table->string('new_status', 20)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('verification_method', 40)->nullable();
            $table->jsonb('evidence_reference')->nullable();
            $table->text('notes')->nullable();
            $table->char('revision_hash', 64)->nullable();
            $table->unsignedSmallInteger('rule_version')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['dietary_claim_id', 'created_at']);
            $table->index(['recipe_id', 'tag_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION dietary_claim_audits_immutable() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'dietary_claim_audits is append-only';
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::unprepared('CREATE TRIGGER dietary_claim_audits_no_update BEFORE UPDATE OR DELETE ON dietary_claim_audits FOR EACH ROW EXECUTE FUNCTION dietary_claim_audits_immutable()');
            DB::unprepared('CREATE TRIGGER dietary_claim_audits_no_truncate BEFORE TRUNCATE ON dietary_claim_audits FOR EACH STATEMENT EXECUTE FUNCTION dietary_claim_audits_immutable()');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dietary_claim_audits');

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS dietary_claim_audits_immutable()');
        }
    }
};
