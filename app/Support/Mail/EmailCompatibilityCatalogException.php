<?php

declare(strict_types=1);

namespace App\Support\Mail;

use JsonSerializable;
use RuntimeException;
use Throwable;

/**
 * Strukturierter, fuer Logs und API-Fehler auswertbarer Katalogfehler.
 *
 * Der Katalog ist Teil des Veroeffentlichungsvertrags. Deshalb werden
 * fehlende und fehlerhafte Daten nicht stillschweigend durch Defaults ersetzt.
 */
final class EmailCompatibilityCatalogException extends RuntimeException implements JsonSerializable
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $catalogPath,
        string $message,
        public readonly ?int $row = null,
        public readonly ?string $column = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array{
     *     code: string,
     *     message: string,
     *     path: string,
     *     row: int|null,
     *     column: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'path' => $this->catalogPath,
            'row' => $this->row,
            'column' => $this->column,
        ];
    }

    /**
     * @return array{
     *     code: string,
     *     message: string,
     *     path: string,
     *     row: int|null,
     *     column: string|null
     * }
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
