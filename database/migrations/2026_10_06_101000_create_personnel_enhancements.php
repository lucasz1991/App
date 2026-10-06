<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->create('personnel_enhancement_locks', function (Blueprint $table): void {
            $table->string('lock_key', 80)->unique();
        });
        DB::table('personnel_enhancement_locks')->insertOrIgnore(['lock_key' => 'absence-policy', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['personnel_workflow_templates', 'personnel_saved_reports', 'personnel_document_templates', 'absence_approval_policies', 'regional_personnel_calendars', 'personnel_surveys'] as $name) {
            $this->create($name, function (Blueprint $table) use ($name): void {
                $table->string('title', 180);
                $table->string('status', 30)->default('draft');
                $table->longText('payload');
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->dateTime('approved_at')->nullable();
                if ($name === 'personnel_saved_reports') {
                    $table->date('next_run_on')->nullable()->index();
                    $table->longText('results')->nullable();
                }
            });
        }
        $this->create('personnel_workflow_runs', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('template_id')->constrained('personnel_workflow_templates')->restrictOnDelete();
            $table->string('title', 180);
            $table->string('status', 30)->default('open');
            $table->date('anchor_on');
            $table->longText('snapshot');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unique(['template_id', 'user_id', 'anchor_on'], 'personnel_template_employee_anchor_unique');
        });
        $this->create('personnel_workflow_steps', function (Blueprint $table): void {
            $table->foreignId('run_id')->constrained('personnel_workflow_runs')->restrictOnDelete();
            $table->foreignId('task_id')->unique()->constrained('personnel_tasks')->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->unsignedInteger('depends_on')->nullable();
            $table->foreignId('delegate_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('escalate_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->date('escalate_on')->nullable();
            $table->dateTime('escalated_at')->nullable();
            $table->unique(['run_id', 'position']);
        });
        $this->create('personnel_signature_requests', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('document_version_id')->constrained('employee_document_versions')->restrictOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('personnel_document_templates')->restrictOnDelete();
            $table->string('title', 180);
            $table->string('status', 30)->default('requested');
            $table->date('due_on');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->longText('payload');
        });
        $this->create('employee_emergency_contacts', function (Blueprint $table): void {
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->longText('payload');
            $table->dateTime('confirmed_at');
        });
        $this->create('absence_approval_steps', function (Blueprint $table): void {
            $table->foreignId('absence_request_id')->constrained('absence_requests')->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('delegate_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('due_at');
            $table->dateTime('decided_at')->nullable();
            $table->string('status', 30)->default('waiting');
            $table->longText('snapshot');
            $table->unique(['absence_request_id', 'position']);
        });
        foreach (['personnel_applicants', 'personnel_development_reviews', 'sickness_evidence_workflows'] as $name) {
            $this->create($name, function (Blueprint $table) use ($name): void {
                $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
                $table->string('title', 180);
                $table->string('status', 30);
                $table->date('due_on')->nullable();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->longText('payload');
                if ($name === 'sickness_evidence_workflows') {
                    $table->foreignId('absence_request_id')->unique()->constrained('absence_requests')->restrictOnDelete();
                }
            });
        }
        $this->create('personnel_survey_responses', function (Blueprint $table): void {
            $table->foreignId('survey_id')->constrained('personnel_surveys')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 30);
            $table->longText('payload')->nullable();
            $table->unique(['survey_id', 'user_id']);
        });
    }

    private function create(string $name, Closure $definition): void
    {
        if (! Schema::hasTable($name)) {
            Schema::create($name, function (Blueprint $table) use ($definition): void {
                $table->id();
                $definition($table);
                $table->unsignedInteger('revision')->default(1);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['personnel_workflow_templates', 'personnel_saved_reports', 'personnel_document_templates', 'absence_approval_policies', 'regional_personnel_calendars', 'personnel_surveys', 'personnel_workflow_runs', 'personnel_workflow_steps', 'personnel_signature_requests', 'employee_emergency_contacts', 'absence_approval_steps', 'personnel_applicants', 'personnel_development_reviews', 'sickness_evidence_workflows', 'personnel_survey_responses'] as $name) {
            if (Schema::hasTable($name) && DB::table($name)->exists()) {
                throw new RuntimeException('Personalprozess- und Nachweishistorie muss erhalten bleiben; geprüfte Vorwärtsmigration verwenden.');
            }
        }
        foreach (['personnel_survey_responses', 'sickness_evidence_workflows', 'personnel_development_reviews', 'personnel_applicants', 'absence_approval_steps', 'employee_emergency_contacts', 'personnel_signature_requests', 'personnel_workflow_steps', 'personnel_workflow_runs', 'personnel_surveys', 'regional_personnel_calendars', 'absence_approval_policies', 'personnel_document_templates', 'personnel_saved_reports', 'personnel_workflow_templates'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::dropIfExists('personnel_enhancement_locks');
    }
};
