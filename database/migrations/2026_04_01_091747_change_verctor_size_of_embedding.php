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
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('ideal_embedding');
            $table->vector('ideal_embedding', 3072)->nullable();
        });

        Schema::table('answer', function (Blueprint $table) {
            $table->dropColumn('answer_embedding');
            $table->vector('answer_embedding', 3072)->nullable();
        });

        Schema::table('study_documents', function (Blueprint $table) {
            $table->dropColumn('content_embedding');
            $table->vector('content_embedding', 3072)->nullable()->after('extracted_text');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('ideal_embedding');
            $table->vector('ideal_embedding', 1536)->nullable();
        });

        Schema::table('answer', function (Blueprint $table) {
            $table->dropColumn('answer_embedding');
            $table->vector('answer_embedding', 1536)->nullable();
        });

        Schema::table('study_documents', function (Blueprint $table) {
            $table->dropColumn('content_embedding');
            $table->vector('content_embedding', 1536)->nullable()->after('extracted_text');
        });
    }
};
