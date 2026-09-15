<?php

namespace App\Http\Controllers;

use App\Models\EmployeeQualification;
use App\Support\Operations\OperationsAccess;
use Illuminate\Support\Facades\Storage;

class OperationsEvidenceController extends Controller
{
    public function __invoke(int $id)
    {
        OperationsAccess::requireReady();
        $record = EmployeeQualification::findOrFail($id);
        $user = auth()->user();
        abort_unless($user->status && (($record->user_id === $user->id && OperationsAccess::isEmployee($user)) || $user->can('operations.qualifications.manage')), 403);
        abort_unless($record->evidence_path && str_starts_with($record->evidence_path, 'operations/evidence/') && Storage::disk('local')->exists($record->evidence_path), 404);

        return Storage::disk('local')->download($record->evidence_path, $record->evidence_name, ['Content-Type' => $record->evidence_mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
