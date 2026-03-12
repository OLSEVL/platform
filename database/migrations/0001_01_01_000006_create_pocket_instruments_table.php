<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pocket_instruments', function (Blueprint $table) {
            $table->id();
            $table->string('instrument', 63)->unique();
            $table->string('display_name', 255)->nullable();
            $table->integer('priority')->default(0);
            $table->text('session_token')->nullable();
            $table->string('uid', 63)->nullable();
            $table->boolean('is_demo')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pocket_instruments');
    }
};
