<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_metadata', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete()->unique();
            $table->json('direct3d_versions')->nullable();
            $table->boolean('vulkan_supported')->nullable();
            $table->boolean('hdr_supported')->nullable();
            $table->boolean('ultrawide_supported')->nullable();
            $table->boolean('dlss_supported')->nullable();
            $table->boolean('fsr_supported')->nullable();
            $table->boolean('ray_tracing_supported')->nullable();
            $table->json('raw_response');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_metadata');
    }
};
