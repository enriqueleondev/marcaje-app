<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('type', 8); // qr | nfc
            $table->string('value', 64);
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Solo tienen valor mientras la credencial está activa. Los índices
            // únicos ignoran los NULL, así que el historial revocado no choca.
            $table->string('active_value', 64)->nullable()->storedAs('case when revoked_at is null then value end');
            $table->unsignedBigInteger('active_student')->nullable()->storedAs('case when revoked_at is null then student_id end');

            $table->unique(['type', 'active_value']);   // un código/pegatina activo = un solo alumno
            $table->unique(['type', 'active_student']); // máximo un QR y un NFC activos por alumno
            $table->index(['student_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credentials');
    }
};
