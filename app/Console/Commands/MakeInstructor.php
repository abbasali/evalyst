<?php

namespace App\Console\Commands;

use App\Actions\Teams\CreateTeam;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('evalyst:make-instructor {email} {--name=} {--course=} {--password=}')]
#[Description('Create an instructor account (accounts are invite-only), optionally with a first course')]
class MakeInstructor extends Command
{
    public function handle(CreateTeam $createTeam): int
    {
        $email = strtolower((string) $this->argument('email'));

        if (Validator::make(['email' => $email], ['email' => 'required|email'])->fails()) {
            $this->components->error('Please provide a valid email address.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            $this->components->info("Instructor {$email} already exists.");
        } else {
            $name = $this->option('name') ?: text('Name', required: true);
            $password = $this->option('password') ?: password('Password', required: true, validate: fn (string $value) => strlen($value) < 8 ? 'Use at least 8 characters.' : null);

            $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->components->info("Created instructor {$email}.");
        }

        if ($course = $this->option('course')) {
            $team = $createTeam->handle($user, (string) $course);

            $this->components->info("Created course \"{$team->name}\".");
        }

        return self::SUCCESS;
    }
}
