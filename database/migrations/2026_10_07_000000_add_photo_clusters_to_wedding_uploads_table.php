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
            // (index 0 = as displayed), replacing the single canonical hash, so
            // two copies are compared at their best-matching orientation.
            $table->json('perceptual_hashes')->nullable()->after('perceptual_hash');
            // Decoded pixel dimensions (photos), used to pick the best copy.
            $table->unsignedInteger('width')->nullable()->after('size_bytes');
            $table->unsignedInteger('height')->nullable()->after('width');
        });

        Schema::table('wedding_uploads', function (Blueprint $table): void {
            $table->dropColumn('perceptual_hash');
        });
    }

    public function down(): void
    {
        Schema::table('wedding_uploads', function (Blueprint $table): void {
            $table->string('perceptual_hash', 64)->nullable()->after('file_hash');
        });

        Schema::table('wedding_uploads', function (Blueprint $table): void {
            $table->dropColumn(['perceptual_hashes', 'width', 'height']);
        });
    }
};
