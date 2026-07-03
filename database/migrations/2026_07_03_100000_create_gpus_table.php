<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gpus', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('manufacturer');
            $table->unsignedInteger('g3d_mark');
            $table->enum('tier', ['low', 'mid', 'high', 'enthusiast']);
            $table->unsignedSmallInteger('released_year');
            $table->timestamps();

            $table->index(['tier', 'g3d_mark']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gpus');
    }
};
