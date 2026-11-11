<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entries', function (Blueprint $table) {
            // Solo para method = manual: por qué no pasó su credencial.
            $table->string('manual_reason', 16)->nullable()->after('created_by'); // forgot | damaged | other
            $table->string('manual_note', 200)->nullable()->after('manual_reason');
        });
    }

    public function down(): void
    {
        Schema::table('entries', function (Blueprint $table) {
            $table->dropColumn(['manual_reason', 'manual_note']);
        });
    }
};
