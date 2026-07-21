<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('app.invitation_to', ['app' => config('app.name')]) }}</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">
                    <tr>
                        <td style="padding:32px;">
                            <h1 style="margin:0 0 16px;font-size:20px;color:#0f172a;">
                                {{ __('app.you_were_invited', ['app' => config('app.name')]) }}
                            </h1>
                            <p style="margin:0 0 12px;font-size:14px;line-height:1.6;color:#334155;">
                                {{ __('app.invitation_body', [
                                    'inviter' => $invitation->inviter?->name ?? __('app.the_administration'),
                                    'app'     => config('app.name'),
                                ]) }}
                                ({{ __('app.role') }}: <strong>{{ $invitation->role === 'admin' ? __('app.role_admin') : __('app.role_staff') }}</strong>).
                            </p>
                            <p style="margin:0 0 24px;font-size:14px;line-height:1.6;color:#334155;">
                                {{ __('app.invitation_click_button') }}
                            </p>
                            <p style="text-align:center;margin:0 0 24px;">
                                <a href="{{ $registrationUrl }}"
                                   style="display:inline-block;background:#e4002b;color:#ffffff;text-decoration:none;font-size:15px;font-weight:bold;padding:12px 28px;border-radius:8px;">
                                    {{ __('app.complete_registration') }}
                                </a>
                            </p>
                            <p style="margin:0 0 8px;font-size:12px;line-height:1.6;color:#64748b;">
                                {{ __('app.invitation_link_fallback') }}<br>
                                <a href="{{ $registrationUrl }}" style="color:#0284c7;word-break:break-all;">{{ $registrationUrl }}</a>
                            </p>
                            @if ($invitation->expires_at)
                                <p style="margin:16px 0 0;font-size:12px;color:#64748b;">
                                    {{ __('app.invitation_valid_until', ['date' => $invitation->expires_at->format('d.m.Y H:i')]) }}
                                </p>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:22px 32px;background:#080b10;border-top:5px solid #e4002b;text-align:center;">
                            <a href="{{ config('app.url') }}" style="display:inline-block;margin-bottom:14px;">
                                <img src="{{ asset('rt-brand/img/logo-mail-dark.png') }}" alt="{{ config('app.name') }}" width="200" style="display:block;width:200px;max-width:100%;height:auto;border:0;">
                            </a>
                            <p style="margin:0;font-size:11px;color:#94a3b8;">
                                {{ config('app.name') }} v{{ config('app.version') }} &middot; RT Rail Time GmbH
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
