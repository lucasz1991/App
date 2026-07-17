# RailTime App

Laravel-Grundinstallation für die RailTime-App: Admin-Oberfläche (Dashboard,
Mitarbeiter- und Berechtigungsverwaltung) und Nutzerbereich (Login, Registrierung,
Dashboard) im Look der RailTime-Website (Layout 3).

## Enthalten

- Laravel 10 + Jetstream (Livewire 3) + Fortify
- Admin-Bereich unter `/administrator` (Rollen `admin` und `staff`)
  - Dashboard
  - Mitarbeiterverwaltung mit Team-basiertem Berechtigungssystem (RBAC)
    – portiert aus dem CBW-Schulnetz-Admin: `RbacCatalog`, Team-Rechte-Matrix,
    Mitarbeiter-Formular-Modal
- Nutzerbereich unter `/dashboard` mit Login (`/login`) und Registrierung (`/register`)
- Login-/Register-Seiten mit dem animierten RailTime-Logo aus der Website
  (3D-Logo mit Orbits/Scan, SVG-Fallback; Assets unter `public/rt-brand/`)

## Berechtigungssystem (RBAC)

- Berechtigungen sind in `app/Support/Rbac/RbacCatalog.php` im Code definiert.
- Gespeichert werden sie pro **Team** als JSON in `teams.rbac_permissions`.
- `AuthServiceProvider` registriert pro Berechtigung ein Gate; Admins haben
  über `Gate::before` immer alle Rechte.
- Mitarbeiter (`role = staff`) erhalten die Rechte ihres aktuellen Teams.
- Verwaltung über *Mitarbeiter → Teams & Rechte* im Adminbereich.

## Lokale Nutzung

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
```

Der Seeder legt einen Admin-Benutzer und das Team „RailTime Team" an.

## Build

```bash
npm run build
```
