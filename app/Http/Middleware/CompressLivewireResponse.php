<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\AcceptHeader;
use Symfony\Component\HttpFoundation\Response;

final class CompressLivewireResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $encodings = AcceptHeader::fromString($request->header('Accept-Encoding', ''));
        $gzip = $encodings->get('gzip') ?? $encodings->get('*');
        if (! $request->isMethod('POST') || ! $request->is('livewire/update')
            || ! $response->isSuccessful() || ! str_contains($response->headers->get('Content-Type', ''), 'application/json')
            || $response->headers->has('Content-Encoding') || ! $gzip || $gzip->getQuality() <= 0 || ! function_exists('gzencode')) {
            return $response;
        }

        $content = $response->getContent();
        if (! is_string($content) || strlen($content) < 4096) {
            return $response;
        }
        $compressed = gzencode($content, 4);
        if ($compressed !== false && strlen($compressed) < strlen($content)) {
            $response->setContent($compressed);
            $response->headers->set('Content-Encoding', 'gzip');
            $response->headers->set('Content-Length', (string) strlen($compressed));
            $response->setVary('Accept-Encoding', false);
        }

        return $response;
    }
}
