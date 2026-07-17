# Einstellungssystem (Settings)

Die App besitzt ein generisches Key-Value-Einstellungssystem auf Basis des
`App\Models\Setting`-Models (Tabelle `settings`, Spalten `type`, `key`, `value`).
Werte werden JSON-codiert gespeichert und beim Lesen gecacht.

## API

```php
use App\Models\Setting;

// Abrufen (mit Cache)
$value = Setting::getValue('gruppe', 'schluessel');

// Ohne Cache
$fresh = Setting::getValueUncached('gruppe', 'schluessel');

// Speichern (invalidiert den Cache)
Setting::setValue('gruppe', 'schluessel', ['beliebige' => 'daten']);
```

## Verwendung in RailTime

Aktuell genutzte Einstellungen:

- `base_api_url` / `base_api_key` — Verbindung der App zur RailTime-Website
  (verwendet u. a. in `MediaController` und `Api/AdminStorageController`).

Neue Einstellungsgruppen können ohne Migration angelegt werden — einfach
`Setting::setValue()` mit einem neuen `type`/`key` aufrufen.
