<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bridge_identities', function (Blueprint $table) {
            $table->id();
            $table->string('app_id', 64);
            $table->string('key_id', 128);
            // Encrypted at rest by the model cast; a database dump does not
            // yield a usable signing key without APP_KEY.
            $table->text('private_key');
            $table->string('control_url', 2048)->nullable();
            $table->text('control_public_key')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bridge_identities');
    }
};
