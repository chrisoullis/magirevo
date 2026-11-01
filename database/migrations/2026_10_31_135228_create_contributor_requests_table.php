<?php

use App\Enums\ContributorRequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contributor_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default(ContributorRequestStatus::Pending->value);
            $table->text('message')->nullable();
            $table->string('locale', 5)->default('en');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        $statuses = implode(', ', array_map(fn (string $status): string => "'{$status}'", ContributorRequestStatus::values()));

        DB::statement("ALTER TABLE contributor_requests ADD CONSTRAINT contributor_requests_status_check CHECK (status IN ({$statuses}))");

        // History is preserved; only one application per user may be open at a time.
        DB::statement("CREATE UNIQUE INDEX contributor_requests_one_pending_unique ON contributor_requests (user_id) WHERE status = 'pending'");
    }

    public function down(): void
    {
        Schema::dropIfExists('contributor_requests');
    }
};
