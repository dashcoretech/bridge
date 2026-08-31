<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bridge_calls', function (Blueprint $table) {
            // A high-water mark per row rather than one per app: a report that
            // fails halfway leaves the rows it did not reach unmarked, and the
            // next run picks up exactly those. A single "last reported id"
            // would either re-send everything or silently skip the gap.
            $table->timestamp('reported_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('bridge_calls', function (Blueprint $table) {
            $table->dropColumn('reported_at');
        });
    }
};
