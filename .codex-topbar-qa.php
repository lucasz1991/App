<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$user = User::updateOrCreate(
    ['email' => 'codex.topbar.qa@railtime.local'],
    [
        'name' => 'Codex Topbar QA',
        'password' => Hash::make('Codex-Topbar-QA-2026!'),
        'role' => 'admin',
        'status' => 1,
    ],
);

$user->forceFill(['email_verified_at' => now()])->save();

echo $user->id;
echo '|'.(Hash::check('Codex-Topbar-QA-2026!', $user->password) ? 'hash-ok' : 'hash-failed');
echo '|'.$user->role;
echo '|'.((int) $user->status);
