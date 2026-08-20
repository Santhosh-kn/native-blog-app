<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('firebase_uid')->nullable();
            $table->text('avatar_url')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('firebase_uid');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['firebase_uid']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'firebase_uid',
                'avatar_url',
            ]);
        });
    }
};