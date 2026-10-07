<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wedding_uploads', function (Blueprint $table): void {
            // Blockhash of each of the eight 90°-rotation/mirror orientations
            // (index 0 = as displayed), so two copies are compared at their
            // best-matching orientation. It supersedes perceptual_hash, which
            // is left in place (no longer written) so this migration is purely
            // additive: the previous release keeps working on the new schema,
            // so a code rollback needs no schema rollback.
            $table->json('perceptual_hashes')->nullable()->after('perceptual_hash');
            // Decoded pixel dimensions (photos), used to pick the best copy.
            $table->unsignedInteger('width')->nullable()->after('size_bytes');
            $table->unsignedInteger('height')->nullable()->after('width');
        });
    }

    public function down(): void
    {
        Schema::table('wedding_uploads', function (Blueprint $table): void {
            $table->dropColumn(['perceptual_hashes', 'width', 'height']);
        });
    }
};
