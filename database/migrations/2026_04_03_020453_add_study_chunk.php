<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Hapus kolom extracted_text dari study_documents (sudah dipindah ke chunks)
        Schema::table('study_documents', function (Blueprint $table) {
            $table->dropColumn('extracted_text');
        });

        // 2. Buat tabel study_chunks
        Schema::create('study_chunks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('study_document_id')->constrained('study_documents')->onDelete('cascade');
            $table->foreignUuid('study_id')->constrained('studies')->onDelete('cascade');
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->timestamps();
        });

        // 3. Tambah kolom vector untuk embedding (pgvector)
        DB::statement('ALTER TABLE study_chunks ADD COLUMN content_embedding vector(1536)');
    }

    public function down(): void
    {
        Schema::dropIfExists('study_chunks');

        Schema::table('study_documents', function (Blueprint $table) {
            $table->text('extracted_text')->nullable();
        });
    }
};