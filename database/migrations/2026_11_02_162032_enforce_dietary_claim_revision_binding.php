<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // An approved claim must name the exact revision it was reviewed against. Any approved claim without
        // one is ambiguous, so it is withdrawn (not deleted) and the withdrawal is audited.
        $ambiguous = DB::table('recipe_dietary_claims')->where('status', 'approved')->whereNull('recipe_revision_id')->get();

        foreach ($ambiguous as $claim) {
            DB::table('recipe_dietary_claims')->where('id', $claim->id)->update(['status' => 'invalidated', 'updated_at' => now()]);
            DB::table('dietary_claim_audits')->insert([
                'dietary_claim_id' => $claim->id,
                'recipe_id' => $claim->recipe_id,
                'tag_id' => $claim->tag_id,
                'recipe_revision_id' => null,
                'action' => 'invalidated',
                'previous_status' => 'approved',
                'new_status' => 'invalidated',
                'actor_id' => null,
                'verification_method' => $claim->verification_method,
                'evidence_reference' => $claim->evidence,
                'notes' => 'Approved claim had no recipe revision reference and was withdrawn.',
                'revision_hash' => $claim->revision_hash,
                'rule_version' => $claim->rule_version,
                'created_at' => now(),
            ]);
        }

        DB::statement("ALTER TABLE recipe_dietary_claims ADD CONSTRAINT recipe_dietary_claims_approved_needs_revision CHECK (status <> 'approved' OR recipe_revision_id IS NOT NULL)");
        DB::statement('CREATE INDEX recipe_dietary_claims_revision_status_index ON recipe_dietary_claims (recipe_id, status)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS recipe_dietary_claims_revision_status_index');
        DB::statement('ALTER TABLE recipe_dietary_claims DROP CONSTRAINT IF EXISTS recipe_dietary_claims_approved_needs_revision');
    }
};
