<?php

namespace App\Services\Dropbox;

use App\Models\DropboxIdentity;
use App\Models\EmployeeCompetencyFact;
use App\Models\Shift;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class CompetencyRestrictions
{
    public function forShift(Shift $shift, Collection $users, bool $lock = false): array
    {
        if (! Schema::hasTable('employee_competency_facts')) {
            return [];
        }
        $identities = DropboxIdentity::whereIn('user_id', $users->pluck('id'))->where('kind', 'employee')->get()->keyBy('id');
        $query = EmployeeCompetencyFact::whereIn('identity_id', $identities->keys());
        if ($lock) {
            $query->lockForUpdate();
        }
        $customer = WorkbookReader::normalize((string) $shift->order?->customer?->company_name);
        $issues = [];
        foreach ($query->get() as $fact) {
            $person = $identities[$fact->identity_id]->user_id;
            $value = $fact->value;
            $color = $value['color'] ?? '';
            $red = preg_match('/^[A-F0-9]{8}$/D', $color) && hexdec(substr($color, 2, 2)) > 160 && hexdec(substr($color, 4, 2)) < 130 && hexdec(substr($color, 6, 2)) < 130;
            if ($fact->kind === 'customer_authorization' && WorkbookReader::normalize($fact->scope ?? '') === $customer && ($red || preg_match('/gesperrt|blocked/iu', $value['value'] ?? ''))) {
                $issues[$person][] = ['code' => 'external_customer_block', 'message' => 'Gemeldete Kundensperre: '.$fact->scope.'.'];
            }
            if ($fact->kind === 'document' && str_contains(WorkbookReader::normalize($fact->name), 'gültig bis') && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value['value'] ?? '') && $value['value'] < $shift->ends_at->setTimezone($shift->timezone)->format('Y-m-d')) {
                $issues[$person][] = ['code' => 'external_document_expired', 'message' => 'Gemeldete Gültigkeit abgelaufen: '.$fact->name.'.'];
            }
        }

        return $issues;
    }
}
