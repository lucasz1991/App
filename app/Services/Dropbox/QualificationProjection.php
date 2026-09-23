<?php

namespace App\Services\Dropbox;

use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\EmployeeCompetencyFact;
use App\Models\EmployeeQualification;

class QualificationProjection
{
    public function refresh(DropboxConnection $connection, int $qualificationId, bool $preview = false): array
    {
        $qualification = EmployeeQualification::find($qualificationId);
        if (! $qualification) {
            return [];
        }
        $facts = EmployeeCompetencyFact::where('qualification_type_id', $qualification->qualification_type_id)
            ->whereIn('identity_id', DropboxIdentity::where('connection_id', $connection->id)->where('user_id', $qualification->user_id)->select('id'))->get();
        foreach ($facts as $fact) {
            if (! $preview) {
                $this->project($fact);
            }
        }

        return $facts->pluck('id')->all();
    }

    public function project(EmployeeCompetencyFact $fact): void
    {
        $value = $this->projectedValue($fact);
        if ($value !== null && $value !== $fact->value) {
            SyncContext::import(fn () => $fact->update(['value' => $value, 'revision' => $fact->revision + 1]));
        }
    }

    public function projectedValue(EmployeeCompetencyFact $fact): ?array
    {
        $identity = DropboxIdentity::findOrFail($fact->identity_id);
        $proofs = EmployeeQualification::where('user_id', $identity->user_id)->where('qualification_type_id', $fact->qualification_type_id)->get();
        $proof = $proofs->where('status', 'approved')->sortByDesc('valid_until')->first() ?? $proofs->sortByDesc('updated_at')->first();
        if (! $proof) {
            return null;
        }
        $value = match ($fact->qualification_field) {
            'valid_until' => $proof->status === 'approved' ? $proof->valid_until?->format('Y-m-d') : '',
            'valid_from' => $proof->status === 'approved' ? $proof->valid_from?->format('Y-m-d') : '',
            'status' => match ($proof->status) {
                'approved' => 'geprüft', 'revoked' => 'widerrufen', 'rejected' => 'abgelehnt', default => 'ungeprüft'
            },
            default => null,
        };
        if ($value === null) {
            return null;
        }
        $color = $proof->status === 'approved' ? ($fact->value['color'] ?? null) : 'FFFF0000';

        return ['value' => $value, 'color' => $color];
    }
}
