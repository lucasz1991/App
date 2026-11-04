<?php

declare(strict_types=1);

namespace App\Support\OutlookAddin;

use Illuminate\Container\Container;
use RuntimeException;

/** Bundle bytes and reader capability are independent of personal template versions. */
final class OutlookAddinBuild
{
    public function __construct(private readonly ?string $directory = null) {}

    public static function contracts(): array
    {
        $root = dirname(__DIR__, 3);
        $data = json_decode((string) file_get_contents($root.'/resources/outlook-addin/contracts.json'), true, flags: JSON_THROW_ON_ERROR);
        if (($data['schema'] ?? null) !== 1 || ! is_int($data['sourceContract'] ?? null) || $data['sourceContract'] < 1
            || ! is_array($data['readerContracts'] ?? null) || ! in_array($data['sourceContract'], $data['readerContracts'], true)) {
            throw new RuntimeException('OUTLOOK_BUILD_CONTRACT_INVALID');
        }
        $template = str_replace("\r\n", "\n", (string) file_get_contents($root.'/resources/mail-templates/u2e-universal-v1.html'));
        if (! is_string($data['sourceTemplate'] ?? null) || ! hash_equals(hash('sha256', $template), $data['sourceTemplate'])) {
            throw new RuntimeException('OUTLOOK_BUILD_SOURCE_PIN_MISMATCH');
        }

        return $data;
    }

    /** @return array{schema:int,revision:string,sourceContract:int,bundles:array{runtime:string,taskpane:string}}|null */
    public function metadata(): ?array
    {
        $bundles = [];
        foreach (['runtime', 'taskpane'] as $name) {
            $directory = $this->directory ?? (Container::getInstance()->bound('path.public')
                ? public_path('outlook-addin') : dirname(__DIR__, 3).'/public/outlook-addin');
            $file = $directory.DIRECTORY_SEPARATOR.$name.'.js';
            if (! is_file($file) || ! is_readable($file)) {
                return null;
            }
            $hash = hash_file('sha256', $file);
            if (! is_string($hash)) {
                return null;
            }
            $bundles[$name] = $hash;
        }
        $contracts = self::contracts();
        $contract = $contracts['sourceContract'];

        return ['schema' => 1, 'revision' => hash('sha256', "railtime-outlook-build-v1\0".$bundles['runtime']."\0".$bundles['taskpane']."\0".$contract),
            'sourceContract' => $contract, 'sourceTemplate' => $contracts['sourceTemplate'], 'bundles' => $bundles];
    }

    public function bundleUrl(string $baseUrl, string $bundle): string
    {
        $metadata = $this->metadata();
        if (! in_array($bundle, ['runtime', 'taskpane'], true)) {
            throw new RuntimeException('OUTLOOK_BUILD_BUNDLE_INVALID');
        }

        return rtrim($baseUrl, '/').'/outlook-addin/'.$bundle.'.js'
            .($metadata === null ? '' : '?v='.$metadata['bundles'][$bundle]);
    }
}
