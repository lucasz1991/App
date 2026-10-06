<?php

namespace App\Services\Operations;

use App\Models\OperationWorkflow;
use App\Models\User;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsDateTime;
use Illuminate\Support\Facades\Validator;

class ReviewedInquiryImportService
{
    public function preview(string $format, string $source, string $reference, User $actor): OperationWorkflow
    {
        OperationsAccess::authorize($actor, 'operations.inquiries.manage');
        Validator::make(compact('format', 'source', 'reference'), ['format' => 'required|in:text,csv', 'source' => 'required|string|max:20000', 'reference' => 'required|string|max:190'])->validate();
        $hash = hash('sha256', $source);
        $rows = [];
        $warnings = [];
        if ($format === 'csv') {
            $lines = preg_split('/\r\n|\r|\n/', trim($source));
            $header = str_getcsv(array_shift($lines), ';', '"', '');
            $allowed = ['title', 'customer_id', 'starts_at', 'ends_at', 'timezone', 'role_name', 'required_staff', 'location_name'];
            abort_unless($header && count(array_unique($header)) === count($header) && ! array_diff($header, $allowed), 422, 'CSV-Spalten prüfen.');
            abort_if(count($lines) > 50, 422, 'Maximal 50 Zeilen.');
            foreach ($lines as $index => $line) {
                if (trim($line) === '') {
                    continue;
                }
                $values = str_getcsv($line, ';', '"', '');
                abort_unless(count($values) === count($header), 422, 'CSV-Zeile '.($index + 2).' prüfen.');
                $rows[] = array_combine($header, $values);
            }
            $warnings[] = 'Datum, Zeitzone und Kundenbezug manuell prüfen.';
        } else {
            $rows[] = ['title' => mb_substr(trim(strtok($source, "\r\n")), 0, 180), 'timezone' => config('operations.display_timezone', 'Europe/Berlin')];
            $warnings[] = 'Freitext: nur Titel übernommen; übrigen Bedarf bewusst erfassen.';
        }
        $duplicates = OperationWorkflow::where('kind', 'import')->get()->filter(fn ($r) => ($r->payload['source_hash'] ?? null) === $hash || ($r->payload['source_reference'] ?? null) === $reference)->pluck('id')->all();

        return app(OperationsWorkflowService::class)->create('import', 'Eingang · '.$reference, ['format' => $format, 'source' => $source, 'source_hash' => $hash, 'source_reference' => $reference, 'rows' => $rows, 'warnings' => $warnings, 'duplicate_ids' => $duplicates, 'external_ai' => false], $actor);
    }

    public function review(int $id, int $revision, array $rows, bool $duplicatesConfirmed, User $actor): OperationWorkflow
    {
        OperationsAccess::authorize($actor, 'operations.inquiries.manage');
        Validator::make(compact('rows'), ['rows' => 'required|array|min:1|max:50', 'rows.*.title' => 'required|string|max:180', 'rows.*.customer_id' => 'required|integer|exists:customers,id', 'rows.*.starts_at' => 'required|string', 'rows.*.ends_at' => 'required|string', 'rows.*.timezone' => 'required|timezone', 'rows.*.role_name' => 'required|string|max:160', 'rows.*.required_staff' => 'required|integer|min:1|max:999', 'rows.*.location_name' => 'nullable|string|max:180'])->validate();
        foreach ($rows as $row) {
            OperationsDateTime::interval($row['starts_at'], $row['ends_at'], $row['timezone']);
        }

        return app(OperationsWorkflowService::class)->change($id, $revision, $actor, function ($r) use ($rows, $duplicatesConfirmed) {
            abort_unless($r->kind === 'import' && $r->status === 'draft', 409);
            abort_if(! empty($r->payload['duplicate_ids']) && ! $duplicatesConfirmed, 422, 'Mögliche Dublette ausdrücklich prüfen.');
            $p = $r->payload;
            $p['rows'] = $rows;
            $p['duplicates_confirmed'] = $duplicatesConfirmed;
            $r->payload = $p;
            $r->status = 'reviewed';

            return 'reviewed';
        }, true);
    }

    public function createInquiries(int $id, int $revision, User $actor): OperationWorkflow
    {
        OperationsAccess::authorize($actor, 'operations.inquiries.manage');

        return app(OperationsWorkflowService::class)->change($id, $revision, $actor, function ($r) use ($actor) {
            abort_unless($r->kind === 'import' && $r->status === 'reviewed', 409);
            $duplicates = OperationWorkflow::where('kind', 'import')->where('id', '!=', $r->id)->orderBy('id')->lockForUpdate()->get()->filter(fn ($other) => ($other->payload['source_hash'] ?? null) === $r->payload['source_hash'] || ($other->payload['source_reference'] ?? null) === $r->payload['source_reference']);
            abort_if($duplicates->isNotEmpty() && ! ($r->payload['duplicates_confirmed'] ?? false), 422, 'Mögliche Dublette erneut ausdrücklich prüfen.');
            $ids = [];
            foreach ($r->payload['rows'] as $index => $row) {
                $inquiry = app(InquiryWorkflowService::class)->save(null, $row + ['channel' => 'manual', 'source_reference' => 'reviewed-import:'.$r->id.':'.$index, 'original' => $r->payload['source']], $actor);
                $ids[] = $inquiry->id;
            }
            $p = $r->payload;
            $p['inquiry_ids'] = $ids;
            $r->payload = $p;
            $r->status = 'converted';

            return 'converted';
        }, true);
    }
}
