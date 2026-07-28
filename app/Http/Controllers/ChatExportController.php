<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\File;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatExportController extends Controller
{
    public function __invoke(Request $request, Chat $chat): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $chat->load('participants');
        $participant = $chat->participantFor($user);

        // Bewusst keine globale Gate-Pruefung: Administratoren duerfen private
        // Chats nur exportieren, wenn sie selbst sichtbarer Teilnehmer sind.
        abort_unless(
            $participant !== null && $participant->pivot?->hidden_at === null,
            403
        );

        $visibleSince = $chat->visibleSinceFor($user);
        $messages = ChatMessage::query()
            ->where('chat_id', $chat->id)
            ->where('view_once', false)
            ->when(
                $visibleSince,
                fn ($query) => $query->where('created_at', '>=', $visibleSince)
            )
            ->with([
                'sender:id,name',
                'files:id,fileable_id,fileable_type,name,mime_type,size',
            ]);

        $filename = sprintf(
            'chat-export-%d-%s.csv',
            $chat->id,
            now()->format('Y-m-d')
        );

        return response()->streamDownload(
            function () use ($messages): void {
                $output = fopen('php://output', 'wb');

                if ($output === false) {
                    return;
                }

                // Excel erkennt UTF-8 bei CSV-Dateien verlaesslich ueber den BOM.
                fwrite($output, "\xEF\xBB\xBF");
                $this->writeCsvRow($output, [
                    __('app.chat_export_date'),
                    __('app.chat_export_time'),
                    __('app.chat_export_sender'),
                    __('app.chat_export_type'),
                    __('app.chat_export_message'),
                    __('app.chat_export_attachments'),
                ]);

                $messages->lazyById(250)->each(function (ChatMessage $message) use ($output): void {
                    $body = rescue(
                        fn (): string => (string) $message->body,
                        __('app.chat_export_decrypt_error'),
                        report: false,
                    );

                    $attachments = $message->files
                        ->map(fn (File $file): string => $this->attachmentMetadata($file))
                        ->implode(' | ');

                    $this->writeCsvRow($output, [
                        $message->created_at?->format('d.m.Y') ?? '',
                        $message->created_at?->format('H:i:s') ?? '',
                        $message->sender?->name ?? __('app.unknown'),
                        $message->message_type ?: 'text',
                        $body,
                        $attachments,
                    ]);
                });

                fclose($output);
            },
            $filename,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'private, no-store, max-age=0, must-revalidate',
                'Pragma' => 'no-cache',
                'Expires' => '0',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    /**
     * @param  resource  $output
     * @param  array<int, mixed>  $values
     */
    private function writeCsvRow($output, array $values): void
    {
        fputcsv(
            $output,
            array_map(fn ($value): string => $this->neutralizeFormula($value), $values),
            ';',
            '"',
            ''
        );
    }

    private function neutralizeFormula(mixed $value): string
    {
        $value = (string) $value;

        // Tabellenprogramme interpretieren auch nach Steuer-/Leerzeichen
        // beginnende =,+,-,@-Werte als Formel. Das Apostroph erzwingt Text.
        if (preg_match('/^[\p{Z}\x00-\x20]*[=+\-@]/u', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }

    private function attachmentMetadata(File $file): string
    {
        $mime = $file->mime_type ?: 'application/octet-stream';
        $size = $file->size !== null ? number_format((int) $file->size, 0, ',', '.') : '?';

        // Keine Speicherpfade, Tokens oder URLs in den Export aufnehmen.
        return sprintf('%s [%s, %s Bytes]', $file->name, $mime, $size);
    }
}
