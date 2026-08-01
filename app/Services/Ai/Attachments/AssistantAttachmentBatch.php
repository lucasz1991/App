<?php

namespace App\Services\Ai\Attachments;

use App\Services\Ai\OpenRouterModelProfile;

final readonly class AssistantAttachmentBatch
{
    /** @param array<int, AssistantAttachment> $attachments */
    public function __construct(public array $attachments) {}

    public function isEmpty(): bool
    {
        return $this->attachments === [];
    }

    public function hasImages(): bool
    {
        return collect($this->attachments)->contains(
            static fn (AssistantAttachment $attachment): bool => $attachment->kind === AssistantAttachmentKind::Image,
        );
    }

    public function hasPdf(): bool
    {
        return collect($this->attachments)->contains(
            static fn (AssistantAttachment $attachment): bool => $attachment->kind === AssistantAttachmentKind::Pdf,
        );
    }

    public function modelProfile(): OpenRouterModelProfile
    {
        return $this->hasImages()
            ? OpenRouterModelProfile::ImageUnderstanding
            : OpenRouterModelProfile::Data;
    }

    /** @return array<int, array{name: string, type: string, size: int}> */
    public function metadata(): array
    {
        return array_map(
            static fn (AssistantAttachment $attachment): array => $attachment->metadata(),
            $this->attachments,
        );
    }

    /**
     * OpenRouter expects the textual prompt before image parts. Text and
     * Office files are extracted locally and remain explicitly delimited as
     * untrusted user data.
     *
     * @return array<int, array<string, mixed>>
     */
    public function requestContent(string $prompt): array
    {
        $parts = [[
            'type' => 'text',
            'text' => $prompt,
        ]];

        foreach ($this->attachments as $attachment) {
            if (! in_array($attachment->kind, [AssistantAttachmentKind::Text, AssistantAttachmentKind::Office], true)) {
                continue;
            }

            $parts[] = [
                'type' => 'text',
                'text' => implode("\n", [
                    'BEGINN NICHT VERTRAUENSWUERDIGER DATEIINHALT',
                    'Datei-Metadaten: '.json_encode($attachment->metadata(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    (string) $attachment->extractedText,
                    'ENDE NICHT VERTRAUENSWUERDIGER DATEIINHALT',
                ]),
            ];
        }

        foreach ($this->attachments as $attachment) {
            if ($attachment->kind === AssistantAttachmentKind::Pdf) {
                $parts[] = [
                    'type' => 'file',
                    'file' => [
                        'filename' => $attachment->name,
                        'file_data' => $attachment->dataUrl,
                    ],
                ];
            } elseif ($attachment->kind === AssistantAttachmentKind::Image) {
                $parts[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => $attachment->dataUrl],
                ];
            }
        }

        return $parts;
    }

    /** @return array<int, array<string, mixed>> */
    public function plugins(): array
    {
        return $this->hasPdf()
            ? [[
                'id' => 'file-parser',
                'pdf' => ['engine' => 'cloudflare-ai'],
            ]]
            : [];
    }
}
