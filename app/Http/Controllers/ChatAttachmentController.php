<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\File;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatAttachmentController extends Controller
{
    public function __invoke(Request $request, File $file): BinaryFileResponse|StreamedResponse
    {
        abort_unless($file->fileable_type === ChatMessage::class, 404);

        $message = ChatMessage::query()
            ->with('chat.participants')
            ->findOrFail($file->fileable_id);
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $chat = $message->chat;
        $participant = $chat?->participantFor($user);

        // Keine Gate-Abkuerzung fuer Administratoren: Chat-Anhaenge gehoeren
        // ausschliesslich sichtbaren Teilnehmern des konkreten Chats.
        abort_unless(
            $participant !== null && $participant->pivot?->hidden_at === null,
            403
        );

        $visibleSince = $chat->visibleSinceFor($user);

        abort_if(
            $visibleSince !== null && $message->created_at->lt($visibleSince),
            403
        );

        if ($message->isVoice() && $message->view_once) {
            abort_if($request->boolean('download'), 403);

            $providedToken = (string) $request->query('voice_token', '');
            $activeToken = Cache::get(ChatMessage::voicePlaybackCacheKey($message->id, $request->user()->id));

            abort_unless(
                $providedToken !== ''
                    && is_string($activeToken)
                    && hash_equals($activeToken, $providedToken),
                410
            );
        }

        $mime = strtolower((string) $file->mime_type);

        // Manche Browser deklarieren reine MediaRecorder-Audioaufnahmen als
        // video/webm. Voice-Nachrichten werden trotzdem immer als Audio ausgeliefert.
        if ($message->isVoice() && in_array($mime, ['video/webm', 'application/octet-stream', ''], true)) {
            $mime = 'audio/webm';
        }
        $canRenderInline = str_starts_with($mime, 'image/')
            || str_starts_with($mime, 'audio/')
            || str_starts_with($mime, 'video/')
            || $mime === 'application/pdf'
            || str_starts_with($mime, 'text/plain')
            || str_starts_with($mime, 'text/csv');

        if ($request->boolean('download') || ! $canRenderInline) {
            return $file->download($file->disk ?: 'private', denyExpired: false);
        }

        $disk = $file->disk ?: 'private';
        $storage = Storage::disk($disk);
        abort_unless($storage->exists($file->path), 404);

        $headers = [
            'Content-Type' => $mime,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                $file->name,
                'attachment'
            ),
            'Cache-Control' => $message->view_once ? 'private, no-store, max-age=0' : 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ];

        // Safari und installierte iOS-Web-Apps laden Audio fast immer ueber
        // HTTP-Byte-Ranges. FilesystemAdapter::response() streamt dagegen
        // stets die komplette Datei mit Status 200, wodurch WebKit den Start
        // je nach Aufnahmeformat verweigert. Chat-Uploads liegen auf dem
        // lokalen privaten Disk; BinaryFileResponse beantwortet Range-Requests
        // korrekt mit 206, Content-Range und der passenden Teil-Laenge.
        $localPath = $storage->path($file->path);

        if (is_file($localPath)) {
            return new BinaryFileResponse($localPath, 200, $headers, public: false);
        }

        // Defensive Rueckfalloption fuer spaeter angebundene Remote-Disks.
        return $storage->response($file->path, $file->name, $headers);
    }
}
