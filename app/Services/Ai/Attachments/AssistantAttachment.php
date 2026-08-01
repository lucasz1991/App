<?php

namespace App\Services\Ai\Attachments;

final readonly class AssistantAttachment
{
    public function __construct(
        public string $name,
        public string $extension,
        public string $mimeType,
        public int $size,
        public AssistantAttachmentKind $kind,
        public ?string $extractedText = null,
        public ?string $dataUrl = null,
    ) {}

    /** @return array{name: string, type: string, size: int} */
    public function metadata(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->extension,
            'size' => $this->size,
        ];
    }
}
