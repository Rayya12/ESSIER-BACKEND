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
        Schema::table('study_chunks', function (Blueprint $table) {
            $table->dropColumn('content_embedding');
        });

        Schema::table('study_chunks', function (Blueprint $table) {
            $table->vector('content_embedding', 3072)->nullable()->after('content');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('study_chunks', function (Blueprint $table) {
            $table->dropColumn('content_embedding');
        });

        Schema::table('study_chunks', function (Blueprint $table) {
            $table->vector('content_embedding', 1536)->nullable()->after('content');
        });
    }
};
