<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class EncryptedDecimal implements CastsAttributes
{
    public function __construct(private readonly int $scale = 2)
    {
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) Crypt::decryptString($value), $this->scale, '.', '');
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace(',', '.', (string) $value);

        return Crypt::encryptString(number_format((float) $normalized, $this->scale, '.', ''));
    }
}
