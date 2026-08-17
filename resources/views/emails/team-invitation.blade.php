{{--
    Projektlokale Fassung der Jetstream-Team-Einladung. Die Mail-Komponenten
    leiten den Inhalt durch vendor/mail/html/layout.blade.php und damit durch
    die aktuell veroeffentlichte RailTime-Nachrichtenschale samt Signatur.
--}}
<x-mail::message>
{{ __('You have been invited to join the :team team!', ['team' => $invitation->team->name]) }}

@if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::registration()))
{{ __('If you do not have an account, you may create one by clicking the button below. After creating an account, you may click the invitation acceptance button in this email to accept the team invitation:') }}

<x-mail::button :url="route('register')">
{{ __('Create Account') }}
</x-mail::button>

{{ __('If you already have an account, you may accept this invitation by clicking the button below:') }}
@else
{{ __('You may accept this invitation by clicking the button below:') }}
@endif

<x-mail::button :url="$acceptUrl">
{{ __('Accept Invitation') }}
</x-mail::button>

{{ __('If you did not expect to receive an invitation to this team, you may discard this email.') }}
</x-mail::message>
