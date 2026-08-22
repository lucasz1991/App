<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input): void
    {
        $emailRule = Rule::email()->rfcCompliant();

        if (($input['email'] ?? null) !== $user->email) {
            $emailRule->validateMxRecord();
        }

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'bail',
                'required',
                'string',
                'max:255',
                $emailRule,
                Rule::unique('users')->ignore($user->id),
            ],
            'photo' => ['nullable', 'image', 'max:10240'],
        ], [
            'email.email' => __('app.email_domain_invalid'),
        ])->validateWithBag('updateProfileInformation');

        if (isset($input['photo'])) {
            $user->updateProfilePhoto($input['photo']);
        }

        if ($input['email'] !== $user->email && $user instanceof MustVerifyEmail) {
            $this->updateVerifiedUser($user, $input);

            return;
        }

        $user->forceFill([
            'name' => $input['name'],
            'email' => $input['email'],
        ])->save();
    }

    /**
     * @param  array<string, string>  $input
     */
    protected function updateVerifiedUser(User $user, array $input): void
    {
        try {
            DB::transaction(function () use ($user, $input): void {
                $user->forceFill([
                    'name' => $input['name'],
                    'email' => $input['email'],
                    'email_verified_at' => null,
                ])->save();

                $user->sendEmailVerificationNotification();
            });
        } catch (TransportExceptionInterface $exception) {
            $user->refresh();

            Log::warning('E-Mail-Verifizierung konnte nicht versendet werden.', [
                'user_id' => (int) $user->getKey(),
                'exception_type' => $exception::class,
            ]);

            throw ValidationException::withMessages([
                'email' => __('app.email_verification_delivery_failed'),
            ])->errorBag('updateProfileInformation');
        }
    }
}
