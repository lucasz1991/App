<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$user = User::updateOrCreate(
    ['email' => 'codex.tabs.click.qa@railtime.local'],
    [
        'name' => 'Codex Tabs Click QA',
        'password' => Hash::make('Codex-Tabs-Click-QA-2026!'),
        'role' => 'admin',
        'status' => 1,
    ],
);

$user->forceFill(['email_verified_at' => now()])->save();

echo $user->id;
