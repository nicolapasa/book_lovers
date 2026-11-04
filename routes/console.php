<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Models\User;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('users:make-admin {email}', function (): int {
    $user = User::query()->where('email', strtolower(trim($this->argument('email'))))->first();
    if ($user === null) {
        $this->error('User not found. Register the account first.');

        return 1;
    }

    $user->is_admin = true;
    $user->save();
    $this->info('Administrator permissions granted to '.$user->email.'.');

    return 0;
})->purpose('Grant administrator permissions to an existing registered account');
