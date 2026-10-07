<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\AiIntakeAttachment;
use App\Models\AiIntakeMessage;
use App\Services\Operations\AiIntakeService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;

class AiIntakeSourceController extends Controller
{
    public function attachment(Request $request, int $id)
    {
        $attachment = AiIntakeAttachment::findOrFail($id);
        $bytes = app(AiIntakeService::class)->attachmentBytes($attachment, $request->user());
        $name = preg_replace('/[\x00-\x1f\x7f\/\\\\]/u', '_', $attachment->file_name) ?: 'Anlage';

        return response($bytes, 200, ['Content-Type' => 'application/octet-stream', 'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $name, 'Anlage'), 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function original(Request $request, int $id)
    {
        $message = AiIntakeMessage::findOrFail($id);
        $bytes = app(AiIntakeService::class)->rawBytes($message, $request->user());

        return response($bytes, 200, ['Content-Type' => 'application/octet-stream', 'Content-Disposition' => 'attachment; filename="Originaleingang.eml"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
