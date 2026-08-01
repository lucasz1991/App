<?php

namespace App\Services\Ai\Attachments;

use Illuminate\Http\UploadedFile;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use PhpOffice\PhpPresentation\IOFactory as PresentationIOFactory;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use Throwable;
use ZipArchive;

class AssistantAttachmentProcessor
{
    /** @var array<string, AssistantAttachmentKind> */
    private const KINDS = [
        'txt' => AssistantAttachmentKind::Text,
        'md' => AssistantAttachmentKind::Text,
        'csv' => AssistantAttachmentKind::Text,
        'json' => AssistantAttachmentKind::Text,
        'docx' => AssistantAttachmentKind::Office,
        'xlsx' => AssistantAttachmentKind::Office,
        'pptx' => AssistantAttachmentKind::Office,
        'pdf' => AssistantAttachmentKind::Pdf,
        'jpg' => AssistantAttachmentKind::Image,
        'jpeg' => AssistantAttachmentKind::Image,
        'png' => AssistantAttachmentKind::Image,
        'webp' => AssistantAttachmentKind::Image,
    ];

    /** @var array<string, array<int, string>> */
    private const MIME_TYPES = [
        'txt' => ['text/plain'],
        'md' => ['text/plain', 'text/markdown'],
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
        'json' => ['application/json', 'text/json', 'text/plain'],
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
            'application/octet-stream',
        ],
        'xlsx' => [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/octet-stream',
        ],
        'pptx' => [
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/zip',
            'application/octet-stream',
        ],
    ];

    /**
     * Validate and process a complete upload set. Raw bytes are held only in
     * this request's DTOs; callers remain responsible for deleting Livewire's
     * temporary files in a finally block.
     *
     * @param  array<int, mixed>  $uploads
     */
    public function process(array $uploads): AssistantAttachmentBatch
    {
        $descriptors = $this->descriptors($uploads);
        $remainingCharacters = max(1000, (int) config('assistant.attachments.max_extracted_characters', 20000));
        $attachments = [];

        foreach ($descriptors as $descriptor) {
            try {
                $attachments[] = $this->processDescriptor($descriptor, $remainingCharacters);
            } catch (AssistantAttachmentException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw new AssistantAttachmentException(
                    'processing_failed',
                    'Die Datei „'.$descriptor['name'].'“ konnte nicht sicher verarbeitet werden.',
                    'attachments.*',
                    $exception,
                );
            }
        }

        return new AssistantAttachmentBatch($attachments);
    }

    /** @param array<int, mixed> $uploads */
    public function validate(array $uploads): void
    {
        $this->descriptors($uploads);
    }

    /**
     * @param  array<int, mixed>  $uploads
     * @return array<int, array{upload: UploadedFile, path: string, name: string, extension: string, mime: string, size: int, kind: AssistantAttachmentKind}>
     */
    private function descriptors(array $uploads): array
    {
        $maxCount = max(1, (int) config('assistant.attachments.max_count', 3));

        if (count($uploads) > $maxCount) {
            throw new AssistantAttachmentException(
                'too_many_files',
                'Du kannst höchstens '.$maxCount.' Dateien gleichzeitig anhängen.',
            );
        }

        $totalBytes = 0;
        $descriptors = [];
        $allowedExtensions = (array) config('assistant.attachments.allowed_extensions', array_keys(self::KINDS));

        foreach ($uploads as $upload) {
            if (! $upload instanceof UploadedFile || ! $upload->isValid()) {
                throw new AssistantAttachmentException(
                    'invalid_upload',
                    'Mindestens eine Datei wurde nicht vollständig hochgeladen.',
                    'attachments.*',
                );
            }

            $path = $upload->getRealPath();
            $size = (int) $upload->getSize();
            $name = $this->safeName($upload->getClientOriginalName());
            $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

            if (! is_string($path) || $path === '' || ! is_file($path) || $size < 1) {
                throw new AssistantAttachmentException(
                    'empty_or_missing',
                    'Die Datei „'.$name.'“ ist leer oder nicht mehr verfügbar.',
                    'attachments.*',
                );
            }

            if (! isset(self::KINDS[$extension]) || ! in_array($extension, $allowedExtensions, true)) {
                throw new AssistantAttachmentException(
                    'extension_not_allowed',
                    'Der Dateityp von „'.$name.'“ wird nicht unterstützt.',
                    'attachments.*',
                );
            }

            $kind = self::KINDS[$extension];
            $maximumBytes = $this->maximumBytesFor($kind);

            if ($size > $maximumBytes) {
                throw new AssistantAttachmentException(
                    'file_too_large',
                    'Die Datei „'.$name.'“ überschreitet die zulässige Größe.',
                    'attachments.*',
                );
            }

            $mime = $this->detectedMimeType($path);
            if (! in_array($mime, self::MIME_TYPES[$extension], true)) {
                throw new AssistantAttachmentException(
                    'mime_mismatch',
                    'Inhalt und Dateiendung von „'.$name.'“ passen nicht zusammen.',
                    'attachments.*',
                );
            }

            $totalBytes += $size;
            $descriptors[] = compact('upload', 'path', 'name', 'extension', 'mime', 'size', 'kind');
        }

        $maxTotalBytes = max(1, (int) config('assistant.attachments.max_total_kilobytes', 15360)) * 1024;
        if ($totalBytes > $maxTotalBytes) {
            throw new AssistantAttachmentException(
                'total_too_large',
                'Die Anhänge dürfen zusammen höchstens 15 MB groß sein.',
            );
        }

        return $descriptors;
    }

    /**
     * @param  array{path: string, name: string, extension: string, mime: string, size: int, kind: AssistantAttachmentKind}  $descriptor
     */
    private function processDescriptor(array $descriptor, int &$remainingCharacters): AssistantAttachment
    {
        return match ($descriptor['kind']) {
            AssistantAttachmentKind::Text => new AssistantAttachment(
                $descriptor['name'],
                $descriptor['extension'],
                $descriptor['mime'],
                $descriptor['size'],
                $descriptor['kind'],
                $this->extractPlainText($descriptor['path'], $descriptor['extension'], $descriptor['name'], $remainingCharacters),
            ),
            AssistantAttachmentKind::Office => new AssistantAttachment(
                $descriptor['name'],
                $descriptor['extension'],
                $descriptor['mime'],
                $descriptor['size'],
                $descriptor['kind'],
                $this->extractOffice($descriptor['path'], $descriptor['extension'], $descriptor['name'], $remainingCharacters),
            ),
            AssistantAttachmentKind::Pdf => new AssistantAttachment(
                $descriptor['name'],
                $descriptor['extension'],
                $descriptor['mime'],
                $descriptor['size'],
                $descriptor['kind'],
                dataUrl: $this->pdfDataUrl($descriptor['path'], $descriptor['name']),
            ),
            AssistantAttachmentKind::Image => $this->processImage($descriptor),
        };
    }

    private function maximumBytesFor(AssistantAttachmentKind $kind): int
    {
        $kilobytes = match ($kind) {
            AssistantAttachmentKind::Text => (int) config('assistant.attachments.max_text_kilobytes', 2048),
            AssistantAttachmentKind::Image => (int) config('assistant.attachments.max_image_kilobytes', 5120),
            AssistantAttachmentKind::Office, AssistantAttachmentKind::Pdf => (int) config('assistant.attachments.max_document_kilobytes', 10240),
        };

        return max(1, $kilobytes) * 1024;
    }

    private function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name));

        return $name === '' ? 'datei' : mb_substr($name, 0, 180);
    }

    private function detectedMimeType(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower(trim((string) $finfo->file($path)));

        return $mime === '' ? 'application/octet-stream' : $mime;
    }

    private function extractPlainText(string $path, string $extension, string $name, int &$remainingCharacters): string
    {
        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            throw new AssistantAttachmentException('read_failed', 'Die Datei „'.$name.'“ konnte nicht gelesen werden.', 'attachments.*');
        }

        $text = $this->decodeText($bytes, $extension === 'json', $name);

        if ($extension === 'json') {
            try {
                $decoded = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
                $text = (string) json_encode(
                    $decoded,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                    64,
                );
            } catch (Throwable $exception) {
                throw new AssistantAttachmentException(
                    'invalid_json',
                    'Die JSON-Datei „'.$name.'“ ist ungültig oder zu tief verschachtelt.',
                    'attachments.*',
                    $exception,
                );
            }
        } elseif ($extension === 'csv') {
            $text = $this->normalizeCsv($text);
        }

        $text = $this->boundedText($text, $remainingCharacters);
        if ($text === '') {
            throw new AssistantAttachmentException('no_text', 'Die Datei „'.$name.'“ enthält keinen lesbaren Text.', 'attachments.*');
        }

        return $text;
    }

    private function decodeText(string $bytes, bool $requireUtf8, string $name): string
    {
        if (str_contains($bytes, "\0") && ! str_starts_with($bytes, "\xFF\xFE") && ! str_starts_with($bytes, "\xFE\xFF")) {
            throw new AssistantAttachmentException('binary_text', 'Die Datei „'.$name.'“ enthält Binärdaten.', 'attachments.*');
        }

        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        } elseif (str_starts_with($bytes, "\xFF\xFE")) {
            $bytes = (string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($bytes, "\xFE\xFF")) {
            $bytes = (string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
        } elseif (! mb_check_encoding($bytes, 'UTF-8')) {
            if ($requireUtf8) {
                throw new AssistantAttachmentException('invalid_encoding', 'Die JSON-Datei „'.$name.'“ muss UTF-8-kodiert sein.', 'attachments.*');
            }

            $encoding = mb_detect_encoding($bytes, ['Windows-1252', 'ISO-8859-1'], true);
            if (! is_string($encoding)) {
                throw new AssistantAttachmentException('invalid_encoding', 'Die Zeichenkodierung von „'.$name.'“ wird nicht unterstützt.', 'attachments.*');
            }

            $bytes = (string) mb_convert_encoding($bytes, 'UTF-8', $encoding);
        }

        $bytes = str_replace(["\r\n", "\r"], "\n", $bytes);
        $bytes = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $bytes);

        return trim($bytes);
    }

    private function normalizeCsv(string $text): string
    {
        $delimiterCounts = [
            ';' => substr_count((string) strtok($text, "\n"), ';'),
            ',' => substr_count((string) strtok($text, "\n"), ','),
            "\t" => substr_count((string) strtok($text, "\n"), "\t"),
        ];
        arsort($delimiterCounts);
        $delimiter = (string) array_key_first($delimiterCounts);

        $stream = fopen('php://temp', 'r+');
        if (! is_resource($stream)) {
            return $text;
        }

        fwrite($stream, $text);
        rewind($stream);
        $rows = [];

        while (($row = fgetcsv($stream, 0, $delimiter, '"', '')) !== false && count($rows) < 500) {
            $cells = array_slice(array_map(
                static fn (mixed $value): string => trim((string) $value),
                $row,
            ), 0, 100);
            $rows[] = implode(' | ', $cells);
        }

        fclose($stream);

        return implode("\n", $rows);
    }

    private function extractOffice(string $path, string $extension, string $name, int &$remainingCharacters): string
    {
        $this->assertSafeOoxml($path, $extension, $name);
        $text = match ($extension) {
            'docx' => $this->extractDocx($path, $remainingCharacters),
            'xlsx' => $this->extractXlsx($path, $remainingCharacters),
            'pptx' => $this->extractPptx($path, $remainingCharacters),
            default => '',
        };

        $text = trim($text);
        if ($text === '') {
            throw new AssistantAttachmentException('no_office_text', 'Die Datei „'.$name.'“ enthält keinen sicher lesbaren Text.', 'attachments.*');
        }

        return $text;
    }

    private function assertSafeOoxml(string $path, string $extension, string $name): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new AssistantAttachmentException('invalid_ooxml', 'Die Office-Datei „'.$name.'“ ist beschädigt.', 'attachments.*');
        }

        try {
            $maxEntries = max(1, (int) config('assistant.attachments.max_zip_entries', 2500));
            if ($zip->numFiles < 1 || $zip->numFiles > $maxEntries) {
                throw new AssistantAttachmentException('zip_entry_limit', 'Die Office-Datei „'.$name.'“ besitzt eine unsichere Archivstruktur.', 'attachments.*');
            }

            $totalUncompressed = 0;
            $maxTotal = max(1, (int) config('assistant.attachments.max_zip_uncompressed_bytes', 50 * 1024 * 1024));
            $maxEntry = max(1, (int) config('assistant.attachments.max_zip_entry_bytes', 16 * 1024 * 1024));
            $maxRatio = max(1, (int) config('assistant.attachments.max_zip_compression_ratio', 100));

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (! is_array($stat)) {
                    throw new AssistantAttachmentException('zip_stat_failed', 'Die Office-Datei „'.$name.'“ konnte nicht sicher geprüft werden.', 'attachments.*');
                }

                $entryName = (string) ($stat['name'] ?? '');
                $lowerName = strtolower($entryName);
                $size = (int) ($stat['size'] ?? 0);
                $compressedSize = (int) ($stat['comp_size'] ?? 0);
                $encrypted = (int) ($stat['encryption_method'] ?? 0);

                if (
                    $entryName === ''
                    || strlen($entryName) > 512
                    || str_contains($entryName, "\0")
                    || str_contains($entryName, '\\')
                    || str_starts_with($entryName, '/')
                    || preg_match('#(^|/)\.\.?(/|$)#', $entryName) === 1
                    || preg_match('/^[A-Za-z]:/', $entryName) === 1
                ) {
                    throw new AssistantAttachmentException('zip_path', 'Die Office-Datei „'.$name.'“ besitzt unsichere Archivpfade.', 'attachments.*');
                }

                if ($encrypted !== 0 || $size < 0 || $size > $maxEntry) {
                    throw new AssistantAttachmentException('zip_entry_unsafe', 'Die Office-Datei „'.$name.'“ besitzt eine unsichere Archivstruktur.', 'attachments.*');
                }

                $totalUncompressed += $size;
                if ($totalUncompressed > $maxTotal || ($size > 1024 && $size / max(1, $compressedSize) > $maxRatio)) {
                    throw new AssistantAttachmentException('zip_bomb', 'Die Office-Datei „'.$name.'“ überschreitet sichere Entpackgrenzen.', 'attachments.*');
                }

                if ($this->isDangerousOfficeEntry($lowerName)) {
                    throw new AssistantAttachmentException('active_office_content', 'Makros, eingebettete Objekte und externe Office-Daten sind nicht erlaubt.', 'attachments.*');
                }

                if (str_ends_with($lowerName, '.xml') || str_ends_with($lowerName, '.rels')) {
                    $xml = $zip->getFromIndex($index);
                    if (! is_string($xml)) {
                        throw new AssistantAttachmentException('xml_read_failed', 'Die Office-Datei „'.$name.'“ konnte nicht sicher geprüft werden.', 'attachments.*');
                    }

                    if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
                        throw new AssistantAttachmentException('xml_entity', 'Die Office-Datei „'.$name.'“ enthält nicht erlaubte XML-Deklarationen.', 'attachments.*');
                    }

                    if (str_ends_with($lowerName, '.rels') && preg_match('/TargetMode\s*=\s*["\']External["\']/i', $xml) === 1) {
                        throw new AssistantAttachmentException('external_relationship', 'Externe Verknüpfungen in Office-Dateien sind nicht erlaubt.', 'attachments.*');
                    }
                }
            }

            $contentTypes = $zip->getFromName('[Content_Types].xml');
            if (! is_string($contentTypes) || ! $this->contentTypesMatch($contentTypes, $extension)) {
                throw new AssistantAttachmentException('ooxml_type_mismatch', 'Inhalt und Dateiendung von „'.$name.'“ passen nicht zusammen.', 'attachments.*');
            }
        } finally {
            $zip->close();
        }
    }

    private function isDangerousOfficeEntry(string $name): bool
    {
        foreach ([
            'vbaproject.bin',
            '/activex/',
            '/embeddings/',
            '/oleobjects/',
            '/macrosheets/',
            '/externallinks/',
            '/querytables/',
            '/connections.xml',
            '/dataconnections/',
            '/customui/',
        ] as $needle) {
            if (str_contains('/'.$name, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function contentTypesMatch(string $contentTypes, string $extension): bool
    {
        if (stripos($contentTypes, 'macroEnabled') !== false || stripos($contentTypes, 'vbaProject') !== false) {
            return false;
        }

        return match ($extension) {
            'docx' => str_contains($contentTypes, 'wordprocessingml.document.main+xml'),
            'xlsx' => str_contains($contentTypes, 'spreadsheetml.sheet.main+xml'),
            'pptx' => str_contains($contentTypes, 'presentationml.presentation.main+xml'),
            default => false,
        };
    }

    private function extractDocx(string $path, int &$remainingCharacters): string
    {
        $document = WordIOFactory::load($path, 'Word2007');
        $chunks = [];

        foreach ($document->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $this->collectWordElement($element, $chunks, $remainingCharacters);
                if ($remainingCharacters <= 0) {
                    break 2;
                }
            }
        }

        return implode("\n", $chunks);
    }

    /** @param array<int, string> $chunks */
    private function collectWordElement(mixed $element, array &$chunks, int &$remainingCharacters): void
    {
        if (! is_object($element) || $remainingCharacters <= 0) {
            return;
        }

        foreach (['getRows', 'getCells', 'getElements'] as $containerMethod) {
            if (method_exists($element, $containerMethod)) {
                $children = $element->{$containerMethod}();
                if (is_iterable($children)) {
                    foreach ($children as $child) {
                        $this->collectWordElement($child, $chunks, $remainingCharacters);
                    }
                }

                return;
            }
        }

        if (method_exists($element, 'getText')) {
            $text = $element->getText();
            if (is_scalar($text) || $text instanceof \Stringable) {
                $this->appendChunk((string) $text, $chunks, $remainingCharacters);
            }
        }
    }

    private function extractXlsx(string $path, int &$remainingCharacters): string
    {
        $reader = SpreadsheetIOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $reader->setReadFilter(new BoundedSpreadsheetReadFilter);
        $spreadsheet = $reader->load($path);
        $chunks = [];
        $cellCount = 0;

        try {
            foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
                $this->appendChunk('Arbeitsblatt: '.$worksheet->getTitle(), $chunks, $remainingCharacters);

                foreach ($worksheet->getCoordinates() as $coordinate) {
                    if (++$cellCount > 5000 || $remainingCharacters <= 0) {
                        break 2;
                    }

                    $value = $worksheet->getCell($coordinate)->getValue();
                    if ($value instanceof RichText) {
                        $value = $value->getPlainText();
                    }

                    if (is_bool($value)) {
                        $value = $value ? 'true' : 'false';
                    }

                    if (is_scalar($value) && trim((string) $value) !== '') {
                        $this->appendChunk($coordinate.': '.(string) $value, $chunks, $remainingCharacters);
                    }
                }
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }

        return implode("\n", $chunks);
    }

    private function extractPptx(string $path, int &$remainingCharacters): string
    {
        $reader = PresentationIOFactory::createReader('PowerPoint2007');
        $presentation = $reader->load($path);
        $chunks = [];

        foreach ($presentation->getAllSlides() as $slideIndex => $slide) {
            $this->appendChunk('Folie '.($slideIndex + 1), $chunks, $remainingCharacters);

            foreach ($slide->getShapeCollection() as $shape) {
                $this->collectPresentationElement($shape, $chunks, $remainingCharacters);
                if ($remainingCharacters <= 0) {
                    break 2;
                }
            }
        }

        return implode("\n", $chunks);
    }

    /** @param array<int, string> $chunks */
    private function collectPresentationElement(mixed $element, array &$chunks, int &$remainingCharacters): void
    {
        if (! is_object($element) || $remainingCharacters <= 0) {
            return;
        }

        foreach (['getRows', 'getCells', 'getShapeCollection'] as $containerMethod) {
            if (method_exists($element, $containerMethod)) {
                $children = $element->{$containerMethod}();
                if (is_iterable($children)) {
                    foreach ($children as $child) {
                        $this->collectPresentationElement($child, $chunks, $remainingCharacters);
                    }
                }

                return;
            }
        }

        if (method_exists($element, 'getParagraphs')) {
            foreach ($element->getParagraphs() as $paragraph) {
                if (! method_exists($paragraph, 'getRichTextElements')) {
                    continue;
                }

                $line = '';
                foreach ($paragraph->getRichTextElements() as $textElement) {
                    if (method_exists($textElement, 'getText')) {
                        $line .= (string) $textElement->getText();
                    }
                }
                $this->appendChunk($line, $chunks, $remainingCharacters);
            }

            return;
        }

        if (method_exists($element, 'getText')) {
            $text = $element->getText();
            if (is_scalar($text) || $text instanceof \Stringable) {
                $this->appendChunk((string) $text, $chunks, $remainingCharacters);
            }
        }
    }

    /** @param array<int, string> $chunks */
    private function appendChunk(string $text, array &$chunks, int &$remainingCharacters): void
    {
        $text = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text));
        if ($text === '' || $remainingCharacters <= 0) {
            return;
        }

        $chunk = mb_substr($text, 0, $remainingCharacters);
        $chunks[] = $chunk;
        $remainingCharacters -= mb_strlen($chunk);
    }

    private function boundedText(string $text, int &$remainingCharacters): string
    {
        $text = trim((string) preg_replace("/\n{4,}/", "\n\n\n", $text));
        if ($remainingCharacters <= 0) {
            return '';
        }

        $bounded = mb_substr($text, 0, $remainingCharacters);
        $remainingCharacters -= mb_strlen($bounded);

        return trim($bounded);
    }

    private function pdfDataUrl(string $path, string $name): string
    {
        $head = file_get_contents($path, false, null, 0, 1024);
        if (! is_string($head) || strpos($head, '%PDF-') === false) {
            throw new AssistantAttachmentException('invalid_pdf', 'Die PDF-Datei „'.$name.'“ ist ungültig.', 'attachments.*');
        }

        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            throw new AssistantAttachmentException('read_failed', 'Die PDF-Datei „'.$name.'“ konnte nicht gelesen werden.', 'attachments.*');
        }

        return 'data:application/pdf;base64,'.base64_encode($bytes);
    }

    /**
     * @param  array{path: string, name: string, extension: string, mime: string, size: int, kind: AssistantAttachmentKind}  $descriptor
     */
    private function processImage(array $descriptor): AssistantAttachment
    {
        $dimensions = @getimagesize($descriptor['path']);
        if (! is_array($dimensions) || ! isset($dimensions[0], $dimensions[1], $dimensions['mime'])) {
            throw new AssistantAttachmentException('invalid_image', 'Das Bild „'.$descriptor['name'].'“ ist ungültig.', 'attachments.*');
        }

        $width = (int) $dimensions[0];
        $height = (int) $dimensions[1];
        $maxPixels = max(1, (int) config('assistant.attachments.max_image_pixels', 40000000));
        if ($width < 1 || $height < 1 || $width > intdiv($maxPixels, max(1, $height))) {
            throw new AssistantAttachmentException('image_dimensions', 'Das Bild „'.$descriptor['name'].'“ überschreitet sichere Abmessungen.', 'attachments.*');
        }

        $manager = new ImageManager(new Driver);
        $image = $manager->read($descriptor['path']);
        $maximumDimension = max(256, (int) config('assistant.attachments.max_image_dimension', 2048));
        $image->orient()->removeAnimation()->removeProfile()->scaleDown($maximumDimension, $maximumDimension);

        [$encoded, $mime] = match ($descriptor['extension']) {
            'png' => [(string) $image->toPng(), 'image/png'],
            'webp' => [(string) $image->toWebp(85, true), 'image/webp'],
            default => [(string) $image->toJpeg(85, false, true), 'image/jpeg'],
        };

        return new AssistantAttachment(
            $descriptor['name'],
            $descriptor['extension'],
            $mime,
            $descriptor['size'],
            AssistantAttachmentKind::Image,
            dataUrl: 'data:'.$mime.';base64,'.base64_encode($encoded),
        );
    }
}
