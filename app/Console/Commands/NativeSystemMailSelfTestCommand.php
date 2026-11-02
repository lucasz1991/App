<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Mail\NativeSystemMailSelfTest;
use Illuminate\Console\Command;
use Throwable;

final class NativeSystemMailSelfTestCommand extends Command
{
    protected $signature = 'mail:native-system-selftest {operation : Fresh lower-case UUID v4} {--send : Submit the already prepared operation once through the configured system SMTP}';

    protected $description = 'Prepare a fixed IT system-template self-test; no send unless explicitly requested.';

    public function handle(NativeSystemMailSelfTest $selfTest): int
    {
        try {
            $record = $this->option('send') ? $selfTest->send((string) $this->argument('operation')) : $selfTest->prepare((string) $this->argument('operation'));
            $this->line(json_encode(array_intersect_key($record, array_flip(['operation', 'state', 'mimeHash', 'bytes', 'sender', 'recipient'])), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $code = preg_match('/\ANATIVE_SYSTEM_TEST_[A-Z_]+\z/', $error->getMessage()) ? $error->getMessage() : 'NATIVE_SYSTEM_TEST_FAILED';
            $this->error($code);

            return self::FAILURE;
        }
    }
}
