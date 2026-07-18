<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use App\Jobs\DeleteTempFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\HttpFoundation\StreamedResponse;

class File extends Model
{
    protected $fillable = [
        'filepool_id',
        'folder_id',
        'user_id',
        'name',
        'path',
        'disk',
        'mime_type',
        'type',
        'shared_roles',
        'size',
        'expires_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'shared_roles' => 'array',
    ];

    /**
     * Rollen, für die eine Datei freigegeben werden kann.
     *
     * @return array<string, string>
     */
    public static function shareableRoles(): array
    {
        return [
            'admin' => 'Administratoren',
            'staff' => 'Mitarbeiter',
            'user' => 'Benutzer',
        ];
    }

    /**
     * Prüft, ob die Datei für die Rolle des Benutzers freigegeben ist.
     * Alle Rollen außer admin/staff zählen als 'user'.
     */
    public function isSharedWithRole(?string $role): bool
    {
        $roles = is_array($this->shared_roles) ? $this->shared_roles : [];
        if ($roles === []) {
            return false;
        }

        $normalized = in_array($role, ['admin', 'staff'], true) ? $role : 'user';

        return in_array($normalized, $roles, true);
    }


    protected static function booted(): void
    {
        static::deleting(function (File $file) {
            $disk = $file->disk ?: 'private';

            if ($file->path && Storage::disk($disk)->exists($file->path)) {
                try {
                    Storage::disk($disk)->delete($file->path);
                } catch (\Throwable $e) {
                    Log::warning('Konnte Datei beim Löschen des File-Modells nicht entfernen', [
                        'file_id' => $file->id,
                        'disk'    => $disk,
                        'path'    => $file->path,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        });
    }


    /* ------------------------------------------
     * zentrale Dateitypen-Definition
     * ----------------------------------------*/
    protected static function fileTypeMap(): array
    {
        return [
            // -----------------------------------------
            // OFFICE & DOKUMENTE
            // -----------------------------------------
            'pdf' => [
                'ext'  => 'pdf',
                'label'=> 'PDF-Dokument',
                'icon' => 'file-pdf.png',
                'mime' => ['application/pdf'],
            ],
            'doc' => [
                'ext'  => 'doc',
                'label'=> 'Microsoft Word-Dokument',
                'icon' => 'file-word.png',
                'mime' => [
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                ],
            ],
            'docx' => [
                'ext'  => 'docx',
                'label'=> 'Microsoft Word-Dokument',
                'icon' => 'file-word.png',
                'mime' => [
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                ],
            ],
            'xls' => [
                'ext'  => 'xls',
                'label'=> 'Microsoft Excel-Arbeitsmappe',
                'icon' => 'file-exel.png',
                'mime' => [
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ],
            ],
            'xlsx' => [
                'ext'  => 'xlsx',
                'label'=> 'Microsoft Excel-Arbeitsmappe',
                'icon' => 'file-exel.png',
                'mime' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            ],
            'ppt' => [
                'ext'  => 'ppt',
                'label'=> 'Microsoft PowerPoint-Präsentation',
                'icon' => 'file-powerpoint.png',
                'mime' => ['application/vnd.ms-powerpoint'],
            ],
            'pptx' => [
                'ext'  => 'pptx',
                'label'=> 'Microsoft PowerPoint-Präsentation',
                'icon' => 'file-powerpoint.png',
                'mime' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            ],
            'csv' => [
                'ext'  => 'csv',
                'label'=> 'CSV-Tabelle',
                'icon' => 'csv-icon.svg',
                'mime' => ['text/csv'],
            ],
            'txt' => [
                'ext'  => 'txt',
                'label'=> 'Textdatei',
                'icon' => 'txt-icon.svg',
                'mime' => ['text/plain'],
            ],
            'xml' => [
                'ext'  => 'xml',
                'label'=> 'XML-Datei',
                'icon' => 'xml-icon.svg',
                'mime' => ['application/xml', 'text/xml'],
            ],
            'html' => [
                'ext'  => 'html',
                'label'=> 'HTML-Dokument',
                'icon' => 'html-icon.svg',
                'mime' => ['text/html'],
            ],
            'htm' => [
                'ext'  => 'htm',
                'label'=> 'HTML-Dokument',
                'icon' => 'html-icon.svg',
                'mime' => ['text/html'],
            ],

            // -----------------------------------------
            // CODE & SCRIPTING
            // -----------------------------------------
            'php' => [
                'ext'  => 'php',
                'label'=> 'PHP-Datei',
                'icon' => 'php-icon.svg',
                'mime' => ['text/x-php', 'application/x-httpd-php', 'application/x-php'],
            ],
            'js' => [
                'ext'  => 'js',
                'label'=> 'JavaScript-Datei',
                'icon' => 'txt-icon.svg',
                'mime' => ['application/javascript', 'text/javascript'],
            ],
            'json' => [
                'ext'  => 'json',
                'label'=> 'JSON-Datei',
                'icon' => 'txt-icon.svg',
                'mime' => ['application/json'],
            ],
            'css' => [
                'ext'  => 'css',
                'label'=> 'CSS-Stylesheet',
                'icon' => 'txt-icon.svg',
                'mime' => ['text/css'],
            ],
            'yaml' => [
                'ext'  => 'yaml',
                'label'=> 'YAML-Datei',
                'icon' => 'txt-icon.svg',
                'mime' => ['application/x-yaml', 'text/yaml'],
            ],
            'sql' => [
                'ext'  => 'sql',
                'label'=> 'SQL-Skript',
                'icon' => 'txt-icon.svg',
                'mime' => ['application/sql', 'text/x-sql'],
            ],
            'md' => [
                'ext'  => 'md',
                'label'=> 'Markdown-Dokument',
                'icon' => 'txt-icon.svg',
                'mime' => ['text/markdown'],
            ],

            // -----------------------------------------
            // GRAFIK / DESIGN
            // -----------------------------------------
            'ai' => [
                'ext'  => 'ai',
                'label'=> 'Adobe Illustrator-Datei',
                'icon' => 'ai-icon.svg',
                'mime' => ['application/postscript', 'application/illustrator'],
            ],
            'eps' => [
                'ext'  => 'eps',
                'label'=> 'PostScript-Datei (EPS)',
                'icon' => 'eps-icon.svg',
                'mime' => ['application/postscript'],
            ],
            'cdr' => [
                'ext'  => 'cdr',
                'label'=> 'CorelDRAW-Datei',
                'icon' => 'cdr-icon.svg',
                'mime' => ['application/vnd.corel-draw'],
            ],
            'raw' => [
                'ext'  => 'raw',
                'label'=> 'RAW-Bilddatei',
                'icon' => 'raw-icon.svg',
                'mime' => ['image/x-raw', 'image/raw'],
            ],
            'gif' => [
                'ext'  => 'gif',
                'label'=> 'GIF-Bild',
                'icon' => 'gif-icon.svg',
                'mime' => ['image/gif'],
            ],
            'jpg' => [
                'ext'  => 'jpg',
                'label'=> 'JPEG-Bild',
                'icon' => null,
                'mime' => ['image/jpeg'],
            ],
            'jpeg' => [
                'ext'  => 'jpeg',
                'label'=> 'JPEG-Bild',
                'icon' => null,
                'mime' => ['image/jpeg'],
            ],
            'png' => [
                'ext'  => 'png',
                'label'=> 'PNG-Bild',
                'icon' => null,
                'mime' => ['image/png'],
            ],
            'svg' => [
                'ext'  => 'svg',
                'label'=> 'SVG-Vektorgrafik',
                'icon' => null,
                'mime' => ['image/svg+xml'],
            ],
            'webp' => [
                'ext'  => 'webp',
                'label'=> 'WebP-Bild',
                'icon' => null,
                'mime' => ['image/webp'],
            ],
            'heic' => [
                'ext'  => 'heic',
                'label'=> 'HEIC-Bild',
                'icon' => null,
                'mime' => ['image/heic', 'image/heif'],
            ],

            // -----------------------------------------
            // AUDIO / VIDEO
            // -----------------------------------------
            'mp3' => [
                'ext'  => 'mp3',
                'label'=> 'MP3-Audiodatei',
                'icon' => 'mp3-icon.svg',
                'mime' => ['audio/mpeg'],
            ],
            'wav' => [
                'ext'  => 'wav',
                'label'=> 'WAV-Audiodatei',
                'icon' => 'wav-icon.svg',
                'mime' => ['audio/wav', 'audio/x-wav'],
            ],
            'mp4' => [
                'ext'  => 'mp4',
                'label'=> 'MP4-Video',
                'icon' => 'mp4-icon.svg',
                'mime' => ['video/mp4'],
            ],
            'avi' => [
                'ext'  => 'avi',
                'label'=> 'AVI-Video',
                'icon' => 'file-video.png',
                'mime' => ['video/x-msvideo'],
            ],
            'mov' => [
                'ext'  => 'mov',
                'label'=> 'MOV-Video',
                'icon' => 'file-video.png',
                'mime' => ['video/quicktime'],
            ],
            'mpg' => [
                'ext'  => 'mpg',
                'label'=> 'MPEG-Video',
                'icon' => 'file-video.png',
                'mime' => ['video/mpeg'],
            ],
            'mpeg' => [
                'ext'  => 'mpeg',
                'label'=> 'MPEG-Video',
                'icon' => 'file-video.png',
                'mime' => ['video/mpeg'],
            ],

            // -----------------------------------------
            // ARCHIVE
            // -----------------------------------------
            'zip' => [
                'ext'  => 'zip',
                'label'=> 'ZIP-Archiv',
                'icon' => 'zip-icon.svg',
                'mime' => ['application/zip', 'application/x-zip-compressed'],
            ],
            'rar' => [
                'ext'  => 'rar',
                'label'=> 'RAR-Archiv',
                'icon' => 'rar-icon.svg',
                'mime' => ['application/x-rar-compressed'],
            ],
            '7z' => [
                'ext'  => '7z',
                'label'=> '7z-Archiv',
                'icon' => 'zip-icon.svg',
                'mime' => ['application/x-7z-compressed'],
            ],
            'tar' => [
                'ext'  => 'tar',
                'label'=> 'TAR-Archiv',
                'icon' => 'zip-icon.svg',
                'mime' => ['application/x-tar'],
            ],
            'gz' => [
                'ext'  => 'gz',
                'label'=> 'GZIP-Archiv',
                'icon' => 'zip-icon.svg',
                'mime' => ['application/gzip'],
            ],

            // -----------------------------------------
            // FALLBACK
            // -----------------------------------------
            '*' => [
                'ext'  => null,
                'label'=> 'Datei',
                'icon' => 'doc-icon.svg',
                'mime' => ['application/octet-stream'],
            ],
        ];
    }


    protected static function resolveFileType(?string $mime, ?string $ext): array
    {
        $types = static::fileTypeMap();
        $mime = strtolower((string) $mime);
        $ext  = strtolower((string) $ext);

        if ($ext && isset($types[$ext])) {
            return $types[$ext];
        }
        foreach ($types as $type) {
            if (in_array($mime, $type['mime'] ?? [], true)) {
                return $type;
            }
        }
        return $types['*'];
    }

    public function getIsImageAttribute(): bool
    {
        $mime = (string) ($this->mime_type ?? '');
        $ext  = strtolower((string) ($this->guessExtension($mime, $this->path) ?? ''));
        $type = static::resolveFileType($mime, $ext);

        if (str_starts_with($mime, 'image/') && $type['icon'] === null) {
            return true;
        }else {
            return false;
        }
    }

    public function getIconOrThumbnailAttribute(): string
    {
        $mime = (string) ($this->mime_type ?? '');
        $ext  = strtolower((string) ($this->guessExtension($mime, $this->path) ?? ''));
        $type = static::resolveFileType($mime, $ext);

        if (str_starts_with($mime, 'image/') && $type['icon'] === null) {
            return $this->getEphemeralPublicUrl(10);
        }
        return asset('site-images/fileicons/' . ($type['icon'] ?? static::fileTypeMap()['*']['icon']));
    }

    public function getSizeFormattedAttribute(): string
    {
        $bytes = (int) $this->size;
        if ($bytes < 1024) {
            return $bytes . ' B';
        } elseif ($bytes < 1000000) {
            return number_format($bytes / 1024, 1, ',', '.') . ' KB';
        } else {
            return number_format($bytes / 1048576, 2, ',', '.') . ' MB';
        }
    }

    public function getEphemeralPublicUrl(int $minutes = 10): string
    {
        $disk      = $this->disk ?: 'private';
        $path      = (string) $this->path;

        // Bei public-Dateien direkt den URL zurückgeben – keine temporäre Kopie nötig
        if ($disk === 'public') {
            return $path && Storage::disk('public')->exists($path)
                ? Storage::disk('public')->url($path)
                : '';
        }

        $publicDisk = 'public';
        $sourceDisk = $disk ?: 'private';
        $cacheKey   = "file:{$this->getKey()}:temp_url";
        $cached = Cache::get($cacheKey);

        if ($cached) {
            $expiresAt = Carbon::parse($cached['expires_at']);
            if (now()->lt($expiresAt) && Storage::disk($publicDisk)->exists($cached['path'])) {
                return Storage::disk($publicDisk)->url($cached['path']);
            }
        }

        $lock = Cache::lock("lock:{$cacheKey}", 10);
        try {
            if ($lock->get()) {
                $cached = Cache::get($cacheKey);
                if ($cached) {
                    $expiresAt = Carbon::parse($cached['expires_at']);
                    if (now()->lt($expiresAt) && Storage::disk($publicDisk)->exists($cached['path'])) {
                        return Storage::disk($publicDisk)->url($cached['path']);
                    }
                }

                $tmpName = Str::uuid()->toString() . '-' . basename($this->path);
                $tmpPath = 'temp/' . $tmpName;
                $read = Storage::disk($sourceDisk)->readStream($this->path);
                if (!$read) {
                    return '';
                    throw new \RuntimeException("Quelle nicht lesbar: {$this->path}");
                }
                Storage::disk($publicDisk)->writeStream($tmpPath, $read);
                if (!Storage::disk($publicDisk)->exists($tmpPath)) {
                    Log::error("Fehler beim Schreiben der temporären Datei: {$tmpPath}");
                    throw new \RuntimeException("Ziel nicht schreibbar: {$tmpPath}");
                } else {
                    // Log::info("Temporäre Datei erstellt: {$tmpPath}");
                }
                if (is_resource($read)) {
                    fclose($read);
                }
                if (config('queue.default') !== 'sync') {
                    // Sync-Queue ignoriert das Delay und wuerde die Temp-Datei
                    // sofort loeschen — dann uebernimmt die geplante Bereinigung.
                    DeleteTempFile::dispatch($publicDisk, $tmpPath)->delay(now()->addMinutes($minutes));
                }
                $payload = [
                    'path'       => $tmpPath,
                    'expires_at' => now()->addMinutes($minutes)->toIso8601String(),
                ];
                Cache::put($cacheKey, $payload, now()->addMinutes($minutes));
                return Storage::disk($publicDisk)->url($tmpPath);
            }
        } finally {
            optional($lock)->release();
        }

        $cached = Cache::get($cacheKey);
        if ($cached && Storage::disk($publicDisk)->exists($cached['path'])) {
            return Storage::disk($publicDisk)->url($cached['path']);
        }

        $tmpName = Str::uuid()->toString() . '-' . basename($this->path);
        $tmpPath = 'temp/' . $tmpName;
        $read = Storage::disk($sourceDisk)->readStream($this->path);
        if (!$read) {
            throw new \RuntimeException("Quelle nicht lesbar: {$this->path}");
        }
        Storage::disk($publicDisk)->writeStream($tmpPath, $read);
        if (!Storage::disk($publicDisk)->exists($tmpPath)) {
            Log::error("Fehler beim Schreiben der temporären Datei: {$tmpPath}");
            throw new \RuntimeException("Ziel nicht schreibbar: {$tmpPath}");
        } else {
            // Log::info("Temporäre Datei erstellt: {$tmpPath}");
        }
        if (is_resource($read)) {
            fclose($read);
        }
        if (config('queue.default') !== 'sync') {
                    // Sync-Queue ignoriert das Delay und wuerde die Temp-Datei
                    // sofort loeschen — dann uebernimmt die geplante Bereinigung.
                    DeleteTempFile::dispatch($publicDisk, $tmpPath)->delay(now()->addMinutes($minutes));
                }
        Cache::put($cacheKey, [
            'path'       => $tmpPath,
            'expires_at' => now()->addMinutes($minutes)->toIso8601String(),
        ], now()->addMinutes($minutes));
        return Storage::disk($publicDisk)->url($tmpPath);
    }

    public function folder(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FileFolder::class, 'folder_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getIsOwnedByAuthUserAttribute(): bool
    {
        $authUser = Auth::user();
        if (!$authUser) {
            return false;
        }
        return (int) $this->user_id === (int) $authUser->id;
    }

    public function getNameWithExtensionAttribute(): string
    {
        $safeName = $this->sanitizeName($this->name ?? 'datei');
        $ext = pathinfo($safeName, PATHINFO_EXTENSION);
        if ($ext !== '') {
            return $safeName;
        }
        $mime = $this->mime_type ?? '';
        $guessed = $this->guessExtension($mime, $this->path);
        $type = static::resolveFileType($mime, $guessed);
        $finalExt = $type['ext'] ?? $guessed;
        return $finalExt ? ($safeName . '.' . $finalExt) : $safeName;
    }

    protected function sanitizeName(string $name): string
    {
        $name = trim($name);
        $name = str_replace(['\\', '/', "\0"], '-', $name);
        return $name === '' ? 'datei' : $name;
    }

    protected function guessExtension(?string $mime, ?string $storagePath): ?string
    {
        if ($mime) {
            try {
                $candidates = MimeTypes::getDefault()->getExtensions($mime);
                if (!empty($candidates)) {
                    return strtolower($candidates[0]);
                }
            } catch (\Throwable $e) {
            }
        }
        if ($storagePath) {
            $ext = pathinfo($storagePath, PATHINFO_EXTENSION);
            if ($ext !== '') {
                return strtolower($ext);
            }
        }
        return null;
    }

    public function download(string $disk = 'private', bool $denyExpired = true): StreamedResponse
    {
        if ($denyExpired && $this->isExpired()) {
            abort(403, 'Diese Datei ist abgelaufen und kann nicht mehr heruntergeladen werden.');
        }
        $filename = $this->name_with_extension ?? $this->name ?? 'datei';
        $mime = $this->mime_type
            ?: (Storage::disk($disk)->exists($this->path) ? (Storage::disk($disk)->mimeType($this->path) ?: null) : null)
            ?: 'application/octet-stream';
        return Storage::disk($disk)->download($this->path, $filename, [
            'Content-Type' => $mime,
        ]);
    }

    public function getMimeTypeForHumans(): string
    {
        $mime = strtolower((string) $this->mime_type);
        $ext  = $this->guessExtension($mime, $this->path);
        $type = static::resolveFileType($mime, $ext);
        $label = $type['label'] ?? 'Datei';
        $extU = $type['ext'] ? strtoupper($type['ext']) : null;
        return $extU ? "{$label} ({$extU})" : $label;
    }

    public function fileable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
