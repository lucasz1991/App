<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait BuildsMinimalRailTimeSchema
{
    protected function buildMinimalRailTimeSchema(): void
    {
        Schema::dropAllTables();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('role')->default('guest');
            $table->boolean('status')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->rememberToken();
            $table->unsignedBigInteger('current_team_id')->nullable();
            $table->string('profile_photo_path', 2048)->nullable();
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->boolean('personal_team')->default(false);
            $table->json('rbac_permissions')->nullable();
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role')->nullable();
            $table->timestamps();
        });

        Schema::create('user_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('street')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('personnel_nr')->nullable();
            $table->string('position')->nullable();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->text('subject');
            $table->text('message');
            $table->unsignedBigInteger('from_user');
            $table->unsignedBigInteger('to_user');
            $table->string('status');
            $table->timestamps();
        });

        Schema::create('files', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('fileable');
            $table->string('name')->nullable();
            $table->string('path')->nullable();
            $table->string('disk')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->nullable();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('chat_user', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('chat_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('chat_id');
            $table->unsignedBigInteger('user_id');
            $table->text('body');
            $table->timestamps();
        });
    }
}
