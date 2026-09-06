<?php

namespace App\Services\DeviceManagement\OpenUemFork;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

/** A verified, secret-free view; acceptance is deliberately not success. */
final readonly class RunStatus
{
    public const VERSION = 'railtime.execution.v1';

    private function __construct(
        public RunReference $reference,
        public string $runId,
        public string $payloadSha256,
        public string $status,
        public ?string $eventId,
        public ?string $finishedAt,
        public int $taskCount,
        public int $failedTaskCount,
        private array $expectedTasks,
    ) {}

    /**
     * The persisted receipt pins every subsequent GET to the original run and
     * snapshot. Raw task output/error never leaves this validation boundary.
     *
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>|null  $receipt
     */
    public static function fromResponse(array $response, RunReference $reference, ?array $receipt = null): self
    {
        foreach ($reference->payload() as $key => $expected) {
            if (($response[$key] ?? null) !== $expected) {
                self::invalid();
            }
        }
        $runId = $response['run_id'] ?? null;
        $digest = $response['payload_sha256'] ?? null;
        $status = $response['status'] ?? null;
        $expectedTasks = $response['expected_tasks'] ?? null;
        if (! RunReference::isUuid($runId) || ! RunReference::isDigest($digest)
            || ! in_array($status, ['queued', 'accepted', 'succeeded', 'failed', 'uncertain'], true)
            || ! is_array($expectedTasks) || ! array_is_list($expectedTasks)
            || $expectedTasks === [] || count($expectedTasks) > 128
            || array_key_exists('snapshot', $response)) {
            self::invalid();
        }
        foreach ($expectedTasks as $index => $name) {
            if (! self::boundedText($name, 256) || $name === '' || in_array($name, array_slice($expectedTasks, 0, $index), true)) {
                self::invalid();
            }
        }
        if ($receipt !== null) {
            foreach ([...$reference->payload(), 'run_id' => $runId, 'payload_sha256' => $digest,
                'expected_tasks_sha256' => self::taskDigest($expectedTasks)] as $key => $expected) {
                if (($receipt[$key] ?? null) !== $expected) {
                    self::invalid();
                }
            }
        }

        $terminal = in_array($status, ['succeeded', 'failed', 'uncertain'], true);
        $result = $response['result'] ?? null;
        if (! $terminal) {
            if ($result !== null) {
                self::invalid();
            }

            return new self($reference, $runId, $digest, $status, null, null, 0, 0, $expectedTasks);
        }
        if (! is_array($result) || array_is_list($result)
            || ($result['version'] ?? null) !== self::VERSION
            || ($result['run_id'] ?? null) !== $runId
            || ($result['correlation_id'] ?? null) !== $reference->correlationId
            || ($result['agent_id'] ?? null) !== $reference->agentId
            || ($result['payload_sha256'] ?? null) !== $digest
            || ($result['status'] ?? null) !== $status
            || ! RunReference::isUuid($result['event_id'] ?? null)
            || ! self::isTimestamp($result['finished_at'] ?? null)) {
            self::invalid();
        }
        $tasks = $result['tasks'] ?? ($status !== 'succeeded' && array_key_exists('tasks', $result) ? [] : null);
        if (! is_array($tasks) || ! array_is_list($tasks) || count($tasks) > 128
            || ! self::boundedText($result['error'] ?? '', 8192)) {
            self::invalid();
        }
        $seen = [];
        $failed = 0;
        foreach ($tasks as $task) {
            if (! is_array($task) || ! self::boundedText($task['name'] ?? null, 256)
                || $task['name'] === '' || in_array($task['name'], $seen, true)
                || ! in_array($task['name'], $expectedTasks, true)
                || ! is_bool($task['succeeded'] ?? null)
                || ! self::boundedText($task['error'] ?? '', 8192)
                || ! self::boundedText($task['output'] ?? '', 8192)) {
                self::invalid();
            }
            $seen[] = $task['name'];
            if (! $task['succeeded'] || ($task['error'] ?? '') !== '') {
                $failed++;
            }
        }
        if ($status === 'succeeded' && (count($tasks) !== count($expectedTasks) || $failed > 0 || ($result['error'] ?? '') !== '')) {
            self::invalid();
        }

        return new self($reference, $runId, $digest, $status, $result['event_id'], $result['finished_at'], count($tasks), $failed, $expectedTasks);
    }

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }

    public function terminal(): bool
    {
        return in_array($this->status, ['succeeded', 'failed', 'uncertain'], true);
    }

    /** @return array<string, string|int> */
    public function receipt(): array
    {
        return [
            ...$this->reference->payload(),
            'run_id' => $this->runId,
            'payload_sha256' => $this->payloadSha256,
            'expected_tasks_sha256' => self::taskDigest($this->expectedTasks),
        ];
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            ...$this->receipt(),
            'status' => $this->status,
            'provider_status' => $this->status,
            'event_id' => $this->eventId,
            'finished_at' => $this->finishedAt,
            'task_count' => $this->taskCount,
            'failed_task_count' => $this->failedTaskCount,
        ];
    }

    private static function boundedText(mixed $value, int $maximum): bool
    {
        return is_string($value) && strlen($value) <= $maximum;
    }

    private static function taskDigest(array $names): string
    {
        return hash('sha256', json_encode($names, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function isTimestamp(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})\z/', $value)) {
            return false;
        }
        try {
            new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();

            return $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
        } catch (Throwable) {
            return false;
        }
    }

    private static function invalid(): never
    {
        throw new RuntimeException('OpenUEM meldete einen ungültigen oder nicht zum gebundenen Auftrag passenden Ausführungsstatus.');
    }
}
