<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('steam_id', 20)->nullable()->unique()->after('email_verified_at');
            $table->timestamp('steam_id_resolved_at')->nullable()->after('steam_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['steam_id']);
            $table->dropColumn(['steam_id', 'steam_id_resolved_at']);
        });
    }
};
