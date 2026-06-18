<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Полностью удаляем ботов типа feshan и связанные записи (cascade по FK).
     */
    public function up(): void
    {
        DB::table('bots')->where('type', 'feshan')->delete();
    }

    public function down(): void
    {
        // Восстановление удалённых ботов невозможно — тип feshan больше не поддерживается.
    }
};
