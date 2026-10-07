<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wedding_uploads', function (Blueprint $table): void {
            // When the photo or video was taken, from its own metadata (read in
            // the browser); null when unknown.
            $table->timestamp('captured_at')->nullable()->after('height');
            // Gallery order: the capture time, or the upload time when that is
            // unknown. A real column so the gallery can cursor-paginate on it.
            $table->timestamp('taken_at')->nullable()->after('captured_at');
            $table->index(['status', 'taken_at', 'id']);
        });

        DB::table('wedding_uploads')->whereNull('taken_at')->update(['taken_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('wedding_uploads', function (Blueprint $table): void {
            $table->dropIndex(['status', 'taken_at', 'id']);
            $table->dropColumn(['captured_at', 'taken_at']);
        });
    }
};
