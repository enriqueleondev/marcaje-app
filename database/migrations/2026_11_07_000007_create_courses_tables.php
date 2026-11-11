<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('users');
            $table->string('name', 100);
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete(); // informativo / filtro
            $table->string('room', 60)->nullable();
            $table->string('roll_call_sort', 12)->default('seat'); // seat | paternal | maternal | first_name | arrival
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['teacher_id', 'is_active']);
        });

        Schema::create('course_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 1 = lunes … 7 = domingo (ISO)
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();

            $table->index(['weekday', 'starts_at']);
        });

        Schema::create('course_student', function (Blueprint $table) {
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->nullable(); // orden por arrastre; null = alfabético
            $table->timestamps();

            $table->primary(['course_id', 'student_id']);
            $table->index('student_id');
        });

        Schema::create('roll_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->dateTime('starts_at'); // copia del horario de ese día (UTC)
            $table->dateTime('ends_at');
            $table->timestamp('confirmed_all_at')->nullable();
            $table->timestamps();

            $table->unique(['course_schedule_id', 'date']);
            $table->index(['course_id', 'date']);
        });

        Schema::create('attendance_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roll_call_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 8)->nullable(); // present | late | absent; null = sin decisión
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('marked_at')->nullable();
            $table->timestamps();

            $table->unique(['roll_call_id', 'student_id']);
            $table->index(['student_id', 'roll_call_id']);
            $table->index(['status', 'entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_marks');
        Schema::dropIfExists('roll_calls');
        Schema::dropIfExists('course_student');
        Schema::dropIfExists('course_schedules');
        Schema::dropIfExists('courses');
    }
};
