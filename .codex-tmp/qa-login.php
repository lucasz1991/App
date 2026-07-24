<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$user = User::query()->where('email', 'codex.qa.admin@railtime.local')->first();

if (! $user) {
    fwrite(STDERR, "QA user missing\n");
    exit(1);
}

$user->forceFill(['password' => Hash::make('Codex-QA-2026!')])->save();
fwrite(STDOUT, json_encode($user->only(['id', 'email', 'role']), JSON_THROW_ON_ERROR).PHP_EOL);
