<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_payroll_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('employer_reference', 64);
            $table->string('personnel_number', 64);
            $table->string('external_employee_reference', 100)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['employer_reference', 'personnel_number'], 'payroll_employer_personnel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_payroll_references');
    }
};
