<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('platform')->nullable();
            $table->string('genre')->nullable();
            $table->enum('status', ['playing', 'backlog', 'completed', 'dropped']);
            $table->unsignedInteger('playtime_minutes')->default(0);
            $table->timestamp('last_played_at')->nullable();
            $table->unsignedBigInteger('steam_app_id')->nullable();
            $table->enum('source', ['manual', 'steam'])->default('manual');
            $table->enum('metadata_status', ['pending', 'ok', 'missing'])->default('missing');
            $table->string('cover_url')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'last_played_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
