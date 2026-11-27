<?php

namespace App\Http\Controllers;

use App\Services\DeviceManagement\Desktop\DeviceClientReleaseService;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Installationsweg der RailTime-App für angemeldete Mitarbeiter. */
final class DesktopAppController extends Controller
{
    /** Feste Adresse für Outlook-Add-in und Kopplungsseite; die Anleitung steht unter „Meine Geräte“. */
    public function install(): RedirectResponse
    {
        abort_unless(auth()->user()?->isActive(), 403);

        return redirect()->to(route('devices.mine').'#railtime-app');
    }

    public function download(DeviceClientReleaseService $releases): StreamedResponse
    {
        abort_unless(auth()->user()?->isActive(), 403);

        return $releases->download();
    }
}
