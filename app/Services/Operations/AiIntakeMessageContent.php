<?php

namespace App\Services\Operations;

use App\Models\AiIntakeMessage;
use DOMDocument;
use DOMElement;
use DOMNode;
use RuntimeException;
use Throwable;
use Webklex\PHPIMAP\Message;

/** A bounded text projection of an immutable source, never executable HTML. */
final class AiIntakeMessageContent
{
    public const VERSION = 'mail-customer-context-v4';

    private const MAX_RAW_BYTES = 25 * 1024 * 1024;

    private const MAX_BODY_BYTES = 2 * 1024 * 1024;

    private const MAX_CHARACTERS = 60000;

    private const MAX_NODES = 20000;

    private const MAX_DEPTH = 64;

    private const BLOCKS = ['p', 'div', 'section', 'article', 'header', 'footer', 'blockquote', 'pre', 'li', 'ul', 'ol', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    private const IGNORED = ['head', 'script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript', 'svg', 'math', 'canvas', 'img', 'input', 'select', 'textarea'];

    public function forMessage(AiIntakeMessage $message): string
    {
        $metadata = $message->metadata ?? [];
        if (($metadata['raw_archive_complete'] ?? true) !== true) {
            throw new RuntimeException('mailbox_content_invalid');
        }
        $path = (string) $message->raw_path;
        $hash = (string) $message->raw_hash;
        if ($path !== '' || $hash !== '') {
            if ($path === '' || ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new RuntimeException('mailbox_content_invalid');
            }

            return $this->fromRaw(app(AiIntakeAttachmentService::class)->bytes($message->intake, $path, $hash));
        }
        if (($metadata['text_archive_complete'] ?? true) !== true) {
            throw new RuntimeException('mailbox_content_invalid');
        }

        // Manual and old records with no archived original retain their complete stored text.
        return $this->fromBodies((string) $message->body, '');
    }

    public function fromRaw(string $raw): string
    {
        if (strlen($raw) > self::MAX_RAW_BYTES) {
            throw new RuntimeException('mailbox_content_limit');
        }
        $separator = strpos($raw, "\r\n\r\n");
        $separator = $separator === false ? strpos($raw, "\n\n") : $separator;
        if ($separator === false || $separator > 262144 || ! preg_match('/^[A-Za-z0-9-]+:/m', substr($raw, 0, $separator))) {
            throw new RuntimeException('mailbox_content_invalid');
        }
        try {
            $message = Message::fromString($raw);
        } catch (Throwable $error) {
            throw new RuntimeException('mailbox_content_invalid', previous: $error);
        }

        return $this->fromBodies($message->getTextBody(), $message->getHTMLBody());
    }

    public function fromBodies(string $plain, string $html): string
    {
        foreach ([$plain, $html] as $body) {
            if (strlen($body) > self::MAX_BODY_BYTES) {
                throw new RuntimeException('mailbox_content_limit');
            }
            if (! mb_check_encoding($body, 'UTF-8')) {
                throw new RuntimeException('mailbox_content_invalid');
            }
        }
        $plain = $this->clean($plain);
        if (trim($html) === '') {
            return $this->complete($plain);
        }
        $document = $this->document($html);
        $hasTable = false;
        foreach ($document->getElementsByTagName('table') as $table) {
            if (! $this->renderable($table)) {
                continue;
            }
            foreach ($table->getElementsByTagName('tr') as $row) {
                if ($this->ownerTable($row) === $table && $this->cells($row) !== []) {
                    $hasTable = true;
                    break 2;
                }
            }
        }
        if (! $hasTable && $plain !== '') {
            return $this->complete($plain);
        }
        $output = '';
        $budget = ['characters' => 0, 'tables' => 0, 'rows' => 0, 'cells' => 0];
        $this->render($document, $output, $budget);

        return $this->complete($output);
    }

    private function document(string $html): DOMDocument
    {
        if (substr_count($html, '<') > self::MAX_NODES) {
            throw new RuntimeException('mailbox_content_limit');
        }
        // MIME already decoded UTF-8. Outlook's old charset meta must not decode it again.
        $html = preg_replace('/<meta\b[^>]*>/is', '', $html) ?? '';
        $html = preg_replace('/<\?xml\b.*?\?>/is', '', $html) ?? '';
        if (trim($html) === '') {
            $html = '<html><body></body></html>';
        }
        $html = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML($html, LIBXML_NONET | LIBXML_COMPACT);
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $loaded) {
            throw new RuntimeException('mailbox_content_invalid');
        }
        foreach ($errors as $error) {
            if ($error->level === LIBXML_ERR_FATAL || stripos($error->message, 'excessive depth') !== false) {
                throw new RuntimeException('mailbox_content_invalid');
            }
        }
        $nodes = 0;
        $stack = [[$document, 0]];
        while ($stack !== []) {
            [$node, $depth] = array_pop($stack);
            if (++$nodes > self::MAX_NODES || $depth > self::MAX_DEPTH) {
                throw new RuntimeException('mailbox_content_limit');
            }
            foreach ($node->childNodes as $child) {
                $stack[] = [$child, $depth + 1];
            }
        }

        return $document;
    }

    private function render(DOMNode $node, string &$output, array &$budget): void
    {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            $this->append($output, preg_replace('/\s+/u', ' ', $this->clean((string) $node->nodeValue)) ?? '', $budget);

            return;
        }
        if ($node instanceof DOMElement) {
            $name = strtolower($node->tagName);
            if (in_array($name, self::IGNORED, true)) {
                return;
            }
            if ($name === 'table') {
                $this->table($node, $output, $budget);

                return;
            }
            if (in_array($name, ['br', 'hr'], true)) {
                $this->append($output, "\n", $budget);

                return;
            }
            $block = in_array($name, self::BLOCKS, true);
            if ($block) {
                $this->append($output, "\n", $budget);
            }
            foreach ($node->childNodes as $child) {
                $this->render($child, $output, $budget);
            }
            if ($block) {
                $this->append($output, "\n", $budget);
            }

            return;
        }
        if ($node instanceof DOMDocument) {
            foreach ($node->childNodes as $child) {
                $this->render($child, $output, $budget);
            }
        }
    }

    private function table(DOMElement $table, string &$output, array &$budget): void
    {
        $this->assertTableStructure($table);
        if (++$budget['tables'] > 100) {
            throw new RuntimeException('mailbox_content_limit');
        }
        $id = $budget['tables'];
        $this->append($output, "\n[TABLE id={$id}]\n", $budget);
        foreach ($table->getElementsByTagName('caption') as $caption) {
            if ($this->ownerTable($caption) === $table) {
                $this->append($output, "[CAPTION]\n", $budget);
                foreach ($caption->childNodes as $child) {
                    $this->render($child, $output, $budget);
                }
                $this->append($output, "\n[/CAPTION]\n", $budget);
            }
        }
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $row) {
            if ($this->ownerTable($row) === $table) {
                $rows[] = $row;
            }
        }
        $occupied = [];
        foreach ($rows as $index => $row) {
            if (++$budget['rows'] > 500) {
                throw new RuntimeException('mailbox_content_limit');
            }
            $number = $index + 1;
            $this->append($output, "[ROW index={$number}]\n", $budget);
            $column = 1;
            foreach ($this->cells($row) as $cell) {
                if (++$budget['cells'] > 5000) {
                    throw new RuntimeException('mailbox_content_limit');
                }
                $colspan = $this->span($cell, 'colspan');
                $rowspan = $this->span($cell, 'rowspan');
                $effectiveRowspan = $rowspan;
                if ($rowspan === 0) {
                    $effectiveRowspan = 1;
                    for ($next = $index + 1; isset($rows[$next]) && $rows[$next]->parentNode === $row->parentNode; $next++) {
                        $effectiveRowspan++;
                    }
                }
                while (($occupied[$column] ?? 0) >= $number) {
                    $column++;
                }
                if ($column + $colspan - 1 > 64) {
                    throw new RuntimeException('mailbox_content_limit');
                }
                for ($current = $column; $current < $column + $colspan; $current++) {
                    if (($occupied[$current] ?? 0) >= $number) {
                        throw new RuntimeException('mailbox_content_invalid');
                    }
                    $occupied[$current] = $number + $effectiveRowspan - 1;
                }
                $type = strtolower($cell->tagName) === 'th' || in_array(strtolower($cell->getAttribute('role')), ['columnheader', 'rowheader'], true) ? 'header' : 'data';
                $scope = strtolower($cell->getAttribute('scope'));
                $scope = in_array($scope, ['row', 'col', 'rowgroup', 'colgroup'], true) ? ' scope='.$scope : '';
                $this->append($output, "[CELL column={$column} type={$type} colspan={$colspan} rowspan={$rowspan}{$scope}]\n", $budget);
                foreach ($cell->childNodes as $child) {
                    $this->render($child, $output, $budget);
                }
                $this->append($output, "\n[/CELL]\n", $budget);
                $column += $colspan;
            }
            $this->append($output, "[/ROW]\n", $budget);
        }
        $this->append($output, "[/TABLE]\n", $budget);
    }

    private function assertTableStructure(DOMElement $table): void
    {
        $stack = iterator_to_array($table->childNodes);
        while ($stack !== []) {
            $node = array_pop($stack);
            if ($node->nodeType === XML_TEXT_NODE && trim($this->clean((string) $node->nodeValue)) !== '') {
                throw new RuntimeException('mailbox_content_invalid');
            }
            if (! $node instanceof DOMElement) {
                continue;
            }
            $name = strtolower($node->tagName);
            if (in_array($name, self::IGNORED, true) || $name === 'caption') {
                continue;
            }
            if (in_array($name, ['td', 'th'], true)) {
                if (! $node->parentNode instanceof DOMElement || strtolower($node->parentNode->tagName) !== 'tr') {
                    throw new RuntimeException('mailbox_content_invalid');
                }

                // Cell contents, including nested tables, are rendered separately and completely.
                continue;
            }
            if ($name === 'table') {
                throw new RuntimeException('mailbox_content_invalid');
            }
            foreach ($node->childNodes as $child) {
                $stack[] = $child;
            }
        }
    }

    private function renderable(DOMNode $node): bool
    {
        for ($parent = $node->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            if (in_array(strtolower($parent->tagName), self::IGNORED, true)) {
                return false;
            }
        }

        return true;
    }

    private function cells(DOMNode $row): array
    {
        $cells = [];
        foreach ($row->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['td', 'th'], true)) {
                $cells[] = $child;
            }
        }

        return $cells;
    }

    private function ownerTable(DOMNode $node): ?DOMElement
    {
        for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
            if ($parent instanceof DOMElement && strtolower($parent->tagName) === 'table') {
                return $parent;
            }
        }

        return null;
    }

    private function span(DOMElement $cell, string $attribute): int
    {
        $value = trim($cell->getAttribute($attribute));
        if ($value === '') {
            return 1;
        }
        if (! preg_match('/^[0-9]{1,3}$/D', $value) || ($attribute === 'colspan' && (int) $value === 0)) {
            throw new RuntimeException('mailbox_content_invalid');
        }
        if ((int) $value > 500) {
            throw new RuntimeException('mailbox_content_limit');
        }

        return (int) $value;
    }

    private function clean(string $text): string
    {
        return str_replace("\u{00a0}", ' ', preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $text) ?? '');
    }

    private function append(string &$output, string $value, array &$budget): void
    {
        $budget['characters'] += mb_strlen($value);
        if ($budget['characters'] > self::MAX_CHARACTERS) {
            throw new RuntimeException('mailbox_content_limit');
        }
        $output .= $value;
    }

    private function complete(string $text): string
    {
        if (mb_strlen($text) > self::MAX_CHARACTERS) {
            throw new RuntimeException('mailbox_content_limit');
        }

        return trim(preg_replace('/\n{3,}/u', "\n\n", preg_replace('/[ \t]+\n/u', "\n", $text) ?? '') ?? '');
    }
}
