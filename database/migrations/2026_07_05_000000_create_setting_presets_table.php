<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setting_presets', function (Blueprint $table) {
            $table->id();
            $table->string('game');
            $table->unsignedInteger('steam_app_id')->nullable();
            $table->enum('goal', ['performance', 'balanced', 'quality']);
            $table->enum('gpu_tier', ['low', 'mid', 'high', 'enthusiast']);
            $table->json('settings');
            $table->text('notes');
            $table->timestamps();

            $table->unique(['game', 'goal', 'gpu_tier']);
            $table->index(['steam_app_id', 'goal', 'gpu_tier']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_presets');
    }
};
