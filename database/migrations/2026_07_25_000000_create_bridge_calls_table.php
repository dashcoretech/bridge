<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bridge_calls', function (Blueprint $table) {
            $table->id();
            $table->string('caller_app')->nullable()->index();
            $table->string('key_id')->nullable();
            $table->string('method', 10);
            $table->string('path');
            $table->string('ability')->nullable();
            $table->string('outcome')->index();
            $table->unsignedSmallInteger('status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bridge_calls');
    }
};
