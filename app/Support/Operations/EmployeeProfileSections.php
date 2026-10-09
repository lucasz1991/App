<?php

namespace App\Support\Operations;

use App\Livewire\Operations\PersonnelReview;
use App\Models\User;
use App\Services\Operations\PersonnelEnhancementService;
use App\Services\Operations\PersonnelProcessService;
use App\Services\Operations\PersonnelScopeService;
use App\Services\Operations\WorkforceAccountService;
use App\Services\Operations\WorkforcePlanReviewService;

/** Individual records only; shared review queues and catalogues remain in their workspaces. */
final class EmployeeProfileSections
{
    public static function forUser(User $actor, User $employee): array
    {
        if (! $actor->status || $employee->role !== 'staff') {
            return [];
        }

        $scope = app(PersonnelScopeService::class);
        $permissions = [];
        $allowed = static function (string $ability) use ($scope, $actor, $employee, &$permissions): bool {
            return $permissions[$ability] ??= $scope->allows($actor, $employee, $ability);
        };
        $sections = [];
        $add = static function (string $key, string $label, string $icon, string $group, string $component, string $tab) use (&$sections): void {
            $sections[$key] = compact('label', 'icon', 'group', 'component', 'tab');
        };

        if ($allowed('operations.qualifications.manage')) {
            if (PersonnelReview::moduleReady('qualifications')) {
                $add('qualifications', 'Nachweise', 'fad fa-award', 'Personalakte', 'qualifications', 'qualifications');
            }
            if (app(PersonnelProcessService::class)->ready()) {
                $add('training', 'Schulungen', 'fad fa-graduation-cap', 'Personalakte', 'workforce', 'training');
            }
        }

        if (! $allowed('employees.master-data.view')) {
            return $sections;
        }

        if (app(PersonnelEnhancementService::class)->ready()) {
            $add('signatures', 'Unterzeichnungen', 'fad fa-file-signature', 'Personalakte', 'enhancements', 'documents');
            if ($allowed('employees.emergency.access')) {
                $add('emergency', 'Notfallkontakt', 'fad fa-phone', 'Personalakte', 'enhancements', 'emergency');
            }
            $add('workflows', 'On-/Offboarding', 'fad fa-list-check', 'Entwicklung & Aufgaben', 'enhancements', 'workflows');
            if ($allowed('employees.development.manage')) {
                $add('development', 'Entwicklung', 'fad fa-seedling', 'Entwicklung & Aufgaben', 'enhancements', 'development');
            }
            if ($allowed('employees.master-data.edit') && $allowed('operations.absences.review')) {
                $add('sickness', 'Krankmeldungsnachweise', 'fad fa-notes-medical', 'Personalakte', 'enhancements', 'sickness');
            }
        }

        if (app(PersonnelProcessService::class)->ready()) {
            $add('tasks', 'Aufgaben', 'fad fa-list-check', 'Entwicklung & Aufgaben', 'workforce', 'tasks');
        }
        if (app(WorkforceAccountService::class)->ready()) {
            $add('accounts', 'Urlaub & Zeitkonto', 'fad fa-clock', 'Arbeitszeit & Regeln', 'workforce', 'account');
            if (PersonnelReview::moduleReady('absences')) {
                $add('absences', 'Abwesenheiten', 'fad fa-calendar-days', 'Arbeitszeit & Regeln', 'workforce', 'absences');
            }
            $add('workModel', 'Arbeitsmodell', 'fad fa-business-time', 'Arbeitszeit & Regeln', 'workforce', 'models');
            $add('vacationPolicy', 'Urlaubsrichtlinie', 'fad fa-umbrella-beach', 'Arbeitszeit & Regeln', 'workforce', 'policies');
            if ($allowed('operations.rules.manage') && PersonnelReview::moduleReady('rules')) {
                $add('ruleAssignments', 'Regelzuordnungen', 'fad fa-sliders', 'Arbeitszeit & Regeln', 'workforce', 'rules');
            }
            if (app(WorkforcePlanReviewService::class)->ready()) {
                $add('planReviews', 'Planprüfungen', 'fad fa-clipboard-check', 'Arbeitszeit & Regeln', 'workforce', 'checks');
            }
            if ($actor->isAdmin() && $scope->ready()) {
                $add('responsibilities', 'Zuständigkeiten', 'fad fa-user-shield', 'Arbeitszeit & Regeln', 'workforce', 'responsibilities');
            }
        }

        return $sections;
    }
}
