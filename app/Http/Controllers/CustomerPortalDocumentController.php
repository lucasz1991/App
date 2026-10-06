<?php

namespace App\Http\Controllers;

use App\Models\CustomerPortalIdentity;
use App\Models\User;
use App\Services\CustomerPortal\CustomerPortalIntakeAttachmentService;
use App\Services\CustomerPortal\CustomerPortalPublicationService;
use App\Services\CustomerPortal\CustomerPortalWorkspaceService;
use Illuminate\Support\Facades\Auth;

class CustomerPortalDocumentController extends Controller
{
    private function identity(): CustomerPortalIdentity
    {
        $identity = Auth::guard('customer_portal')->user();
        abort_unless($identity instanceof CustomerPortalIdentity, 401);

        return $identity;
    }

    public function download(int $customer, int $publication, CustomerPortalPublicationService $service)
    {
        return $service->download($this->identity(), $customer, $publication);
    }

    public function attachment(int $customer, int $attachment, CustomerPortalIntakeAttachmentService $service)
    {
        return $service->download($this->identity(), $customer, $attachment);
    }

    public function report(int $customer, CustomerPortalWorkspaceService $service)
    {
        $csv = $service->report($this->identity(), $customer);

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="railtime-kundenleistungen.csv"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function managerAttachment(int $id, CustomerPortalIntakeAttachmentService $service)
    {
        abort_unless(auth()->user() instanceof User, 401);

        return $service->managerDownload(auth()->user(), $id);
    }

    public function printReport(int $customer, CustomerPortalWorkspaceService $service)
    {
        return response()->view('customer-portal.report-print', $service->workspace($this->identity(), $customer, 'reports'))->header('Cache-Control', 'no-store, private');
    }

    public function managerDocument(int $id, CustomerPortalPublicationService $service)
    {
        abort_unless(auth()->user() instanceof User, 401);

        return $service->managerDownload(auth()->user(), $id);
    }
}
