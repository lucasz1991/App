<?php

namespace App\Support\Calls;

use Carbon\CarbonImmutable;
use Livekit\EgressInfo;
use Livekit\FileInfo;

final readonly class EgressSnapshot
{
    public function __construct(
        public string $egressId,
        public string $roomName,
        public int $status,
        public ?CarbonImmutable $startedAt = null,
        public ?CarbonImmutable $endedAt = null,
        public ?string $filename = null,
        public ?string $location = null,
        public ?int $sizeBytes = null,
        public ?int $durationMs = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {
    }

    public static function fromLiveKit(EgressInfo $info): self
    {
        $file = self::firstFile($info);

        return new self(
            egressId: (string) $info->getEgressId(),
            roomName: (string) $info->getRoomName(),
            status: (int) $info->getStatus(),
            startedAt: self::fromNanoseconds($info->getStartedAt()),
            endedAt: self::fromNanoseconds($info->getEndedAt()),
            filename: self::nullableString($file?->getFilename()),
            location: self::nullableString($file?->getLocation()),
            sizeBytes: self::positiveInteger($file?->getSize()),
            durationMs: self::nanosecondsToMilliseconds($file?->getDuration()),
            errorCode: ((int) $info->getErrorCode()) === 0 ? null : (string) $info->getErrorCode(),
            errorMessage: self::nullableString($info->getError()),
        );
    }

    private static function firstFile(EgressInfo $info): ?FileInfo
    {
        foreach ($info->getFileResults() as $file) {
            return $file;
        }

        return $info->getFile();
    }

    private static function fromNanoseconds(int|string|null $value): ?CarbonImmutable
    {
        $nanoseconds = (int) $value;

        return $nanoseconds > 0
            ? CarbonImmutable::createFromTimestampUTC(intdiv($nanoseconds, 1_000_000_000))
            : null;
    }

    private static function nanosecondsToMilliseconds(int|string|null $value): ?int
    {
        $nanoseconds = (int) $value;

        return $nanoseconds > 0 ? intdiv($nanoseconds, 1_000_000) : null;
    }

    private static function positiveInteger(int|string|null $value): ?int
    {
        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }

    private static function nullableString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
