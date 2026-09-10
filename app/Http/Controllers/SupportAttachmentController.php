<?php

namespace App\Http\Controllers;

use App\Models\SupportCaseAttachment;
use App\Services\Support\SupportAttachmentService;
use Illuminate\Http\Request;

class SupportAttachmentController extends Controller
{
    public function __invoke(Request $request, string $attachment, SupportAttachmentService $service)
    {
        $file = SupportCaseAttachment::query()->where('public_id', $attachment)->firstOrFail();

        return response($service->contents($file, $request->user()))->withHeaders(['Content-Type' => 'application/octet-stream', 'Content-Disposition' => 'attachment; filename="support-attachment.'.match ($file->mime_type) {
            'image/png' => 'png', 'image/jpeg' => 'jpg', default => 'pdf'
        }.'"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
