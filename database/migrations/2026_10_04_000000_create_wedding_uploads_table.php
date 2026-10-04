<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wedding_uploads', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();

            // Guest identity is self-asserted (email gate, no verification).
            // Ownership of an in-flight upload is proven by the SHA-256 of a
            // random per-session token, never by the email.
            $table->string('guest_email');
            $table->string('guest_name', 60)->nullable();
            $table->char('guest_token_hash', 64);

            $table->string('kind', 10); // photo | video
            $table->string('status', 10)->default('pending'); // pending | ready | hidden

            $table->string('object_key');
            $table->string('display_key')->nullable();
            $table->string('thumbnail_key')->nullable();
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedBigInteger('expected_size_bytes')->nullable();

            // Exact (SHA-256 hex) and perceptual (base64 blockhash) hashes,
            // both computed in the browser.
            $table->char('file_hash', 64)->nullable()->index();
            $table->string('perceptual_hash', 64)->nullable();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('wedding_uploads')->nullOnDelete();

            $table->string('multipart_upload_id', 1024)->nullable();
            $table->unsignedInteger('multipart_part_size_bytes')->nullable();
            $table->unsignedInteger('multipart_max_part_number')->nullable();

            $table->string('hls_content_id')->nullable();
            $table->timestamp('hls_checked_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wedding_uploads');
    }
};
