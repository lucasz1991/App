<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatAttachmentController extends Controller
{
    public function __invoke(Request $request, File $file): StreamedResponse
    {
        abort_unless($file->fileable_type === ChatMessage::class, 404);

        $message = ChatMessage::query()->findOrFail($file->fileable_id);
        abort_unless(
            $message->chat()->whereHas('participants', fn ($query) => $query->where('users.id', $request->user()->id))->exists(),
            403
        );

        if ($request->boolean('download')) {
            return $file->download($file->disk ?: 'private', denyExpired: false);
        }

        $disk = $file->disk ?: 'private';
        abort_unless(Storage::disk($disk)->exists($file->path), 404);

        return Storage::disk($disk)->response($file->path, $file->name, [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="' . addslashes($file->name) . '"',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
