<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use App\Models\File;
use Illuminate\Support\Facades\Cache;
use App\Jobs\DeleteTempFile;

class AdminStorageController extends Controller
{
    protected function validateApiKey(Request $request): bool
    {
        $incomingKey = $request->header('X-API-KEY');
        $storedKey   = Setting::where('key', 'base_api_key')->value('value');
        return $incomingKey && $storedKey && hash_equals($storedKey, $incomingKey);
    }

    public function resolveFileUrl(Request $request): JsonResponse
    {
        if (!$this->validateApiKey($request)) {
            return response()->json(['success' => false, 'error' => 'Ungültiger API-Key.'], 403);
        }

        $data = $request->validate([
            'file_id' => 'required_without:url|nullable|integer|min:1',
            'url'     => 'required_without:file_id|nullable|string|max:2048',
            'disk'    => 'nullable|in:private,public',
            'expires' => 'nullable|integer|min:30|max:86400',
        ]);

        $ttl     = $data['expires'] ?? 300;
        $minutes = (int) max(1, ceil($ttl / 60)); 
        $disk    = ($data['disk'] ?? 'private') === 'public' ? 'public' : 'private';

        if (!empty($data['file_id'])) {
            $file = \App\Models\File::find($data['file_id']);
            if (!$file) {
                return response()->json(['success' => false, 'error' => 'Datei nicht gefunden.'], 404);
            }

            // Nutze die vorhandene Funktion aus dem Model
            $url = $file->getEphemeralPublicUrl($minutes);

            if (!$url) {
                return response()->json([
                    'success' => false,
                    'error'   => 'Ephemeral-URL konnte nicht erzeugt werden.',
                ], 500);
            }

            return response()->json([
                'success'     => true,
                'url'         => $url,
                'strategy'    => 'model-ephemeral-copy',
                'source'      => 'file_id',
                'expires_in'  => $minutes * 60,
                'expires_at'  => now()->addMinutes($minutes)->toIso8601String(),
            ]);
        }

        // -------------------- B) URL/Pfad --------------------
        $rawUrl = trim($data['url']);

        // Externe absolute URL -> einfach durchreichen
        if (preg_match('#^https?://#i', $rawUrl)) {
            return response()->json([
                'success'  => true,
                'url'      => $rawUrl,
                'strategy' => 'pass-through',
                'source'   => 'absolute-url',
            ]);
        }

        // interner Storage-Pfad
        $path = preg_replace('#[^A-Za-z0-9/_\-.]#', '', ltrim($rawUrl, '/'));
        if (!$path) {
            return response()->json(['success' => false, 'error' => 'Ungültiger Pfad.'], 422);
        }

        // Erst versuchen: temporaryUrl (falls S3/R2 etc.)
        try {
            if (!Storage::disk($disk)->exists($path)) {
                return response()->json(['success' => false, 'error' => 'Datei nicht gefunden.'], 404);
            }
            $tmpUrl = Storage::disk($disk)->temporaryUrl($path, now()->addSeconds($ttl));

            return response()->json([
                'success'     => true,
                'url'         => $tmpUrl,
                'disk'        => $disk,
                'path'        => $path,
                'strategy'    => 'disk-temporary-url',
                'expires_in'  => $ttl,
                'expires_at'  => now()->addSeconds($ttl)->toIso8601String(),
                'source'      => 'storage-path',
            ]);
        } catch (\Throwable $e) {
            \Log::warning('temporaryUrl fehlgeschlagen', [
                'disk' => $disk,
                'path' => $path,
                'err'  => $e->getMessage(),
            ]);
            // Fallback auf lokale Temp-Kopie
            $url = $this->makePublicTempCopyUrl($disk, $path, $minutes);
            if (!$url) {
                return response()->json([
                    'success' => false,
                    'error'   => 'Ephemeral-URL (Pfad) konnte nicht erzeugt werden.',
                ], 500);
            }

            return response()->json([
                'success'     => true,
                'url'         => $url,
                'strategy'    => 'controller-ephemeral-copy',
                'source'      => 'storage-path',
                'expires_in'  => $minutes * 60,
                'expires_at'  => now()->addMinutes($minutes)->toIso8601String(),
            ]);
        }
    }

    protected function makePublicTempCopyUrl(string $sourceDisk, string $sourcePath, int $minutes): ?string
    {
        $publicDisk = 'public';
        $cacheKey   = "file:path:{$sourceDisk}:{$sourcePath}:temp_url";

        // Cache-Treffer?
        if ($cached = Cache::get($cacheKey)) {
            if (Storage::disk($publicDisk)->exists($cached['path']) && now()->lt(\Illuminate\Support\Carbon::parse($cached['expires_at']))) {
                return Storage::disk($publicDisk)->url($cached['path']);
            }
        }

        // Lock gegen parallele Kopien
        $lock = Cache::lock("lock:{$cacheKey}", 10);
        try {
            if ($lock->get()) {
                // Erneut prüfen (Double-Checked)
                if ($cached = Cache::get($cacheKey)) {
                    if (Storage::disk($publicDisk)->exists($cached['path']) && now()->lt(\Illuminate\Support\Carbon::parse($cached['expires_at']))) {
                        return Storage::disk($publicDisk)->url($cached['path']);
                    }
                }

                if (!Storage::disk($sourceDisk)->exists($sourcePath)) {
                    return null;
                }

                $tmpName = Str::uuid()->toString() . '-' . basename($sourcePath);
                $tmpPath = 'temp/' . $tmpName;

                $read = Storage::disk($sourceDisk)->readStream($sourcePath);
                if (!$read) {
                    return null;
                }

                Storage::disk($publicDisk)->writeStream($tmpPath, $read);
                if (is_resource($read)) {
                    fclose($read);
                }

                if (!Storage::disk($publicDisk)->exists($tmpPath)) {
                    \Log::error("Fehler beim Schreiben der temporären Datei: {$tmpPath}");
                    return null;
                }

                // Auto-Cleanup + Cache
                DeleteTempFile::dispatch($publicDisk, $tmpPath)->delay(now()->addMinutes($minutes));
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

        // Letzter Versuch: aus Cache lesen
        if ($cached = Cache::get($cacheKey)) {
            if (Storage::disk($publicDisk)->exists($cached['path'])) {
                return Storage::disk($publicDisk)->url($cached['path']);
            }
        }

        return null;
    }

    public function store(Request $request): JsonResponse
    {
        if (!$this->validateApiKey($request)) {
            return response()->json(['success' => false, 'error' => 'Ungültiger API-Key.'], 403);
        }

        $validated = $request->validate([
            'file' => 'required|max:409600',  // max 400MB
            'folder' => 'nullable|string',
            'visibility' => 'nullable|in:public,private',
        ]);

        $visibility = $validated['visibility'] ?? 'private';
        $disk = $visibility === 'public' ? 'public' : 'private';

        // Ordner sanft normalisieren (Unterordner erlauben)
        $folderInput = trim($validated['folder'] ?? 'uploads/files', '/');
        $folder = preg_replace('#[^A-Za-z0-9/_\-.]#', '', $folderInput) ?: 'uploads/files';

        $file = $validated['file'];
        $origBase = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeBase = Str::slug($origBase) ?: 'file';
        $ext = strtolower($file->getClientOriginalExtension());
        $filename = Str::random(12) . '-' . $safeBase . '.' . $ext;

        $path = $file->storeAs($folder, $filename, $disk);

        $size = Storage::disk($disk)->size($path);
        $mime = $file->getMimeType();
        $url  = $disk === 'public' ? Storage::disk($disk)->url($path) : null;

        Log::info('Media gespeichert', ['disk' => $disk, 'path' => $path, 'mime' => $mime, 'size' => $size]);

        return response()->json([
            'success'    => true,
            'url'        => $url,                  // nur bei public
            'path'       => $path,
            'name'       => basename($path),
            'original'   => $file->getClientOriginalName(),
            'mime'       => $mime,
            'type'       => $ext,
            'size'       => $size,
            'visibility' => $visibility,
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        if (!$this->validateApiKey($request)) {
            return response()->json(['success' => false, 'error' => 'Ungültiger API-Key.'], 403);
        }

        $validated = $request->validate([
            'path'       => 'required|string',
            'visibility' => 'nullable|in:public,private',
        ]);

        // Pfad sanitisieren
        $rawPath = ltrim($validated['path'], '/');
        $path = preg_replace('#[^A-Za-z0-9/_\-.]#', '', $rawPath);

        // Disk bestimmen oder beide prüfen
        $disks = isset($validated['visibility'])
            ? [ $validated['visibility'] === 'public' ? 'public' : 'private' ]
            : ['private','public'];

        foreach ($disks as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
                return response()->json(['success' => true, 'message' => 'Datei gelöscht.', 'disk' => $disk, 'path' => $path]);
            }
        }

        return response()->json(['success' => false, 'error' => 'Datei nicht gefunden.'], 404);
    }




}
