<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kiosks', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('location', 120)->nullable();
            $table->char('token_hash', 64)->unique(); // sha256; el token solo se muestra una vez
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kiosk_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('credential_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 8); // nfc | qr | manual
            $table->dateTime('scanned_at');  // hora del dispositivo (importa con la cola offline)
            $table->dateTime('received_at'); // hora del servidor
            $table->uuid('client_uuid')->nullable()->unique(); // reenvíos de la cola no duplican
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['student_id', 'scanned_at']);
            $table->index('scanned_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entries');
        Schema::dropIfExists('kiosks');
    }
};
