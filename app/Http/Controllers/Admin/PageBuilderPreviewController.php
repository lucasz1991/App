<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MarketingCreativeFormat;
use App\Http\Controllers\Controller;
use App\Http\Responses\PageBuilderPreviewResponse;
use App\Models\MailDocument;
use App\Models\MarketingCreative;
use App\Services\PageBuilder\PageBuilderPreviewService;
use Illuminate\Http\Request;

final class PageBuilderPreviewController extends Controller
{
    private const CSP = "default-src 'none'; base-uri 'none'; connect-src 'none'; font-src data:; form-action 'none'; frame-ancestors 'self'; img-src data:; media-src 'none'; object-src 'none'; script-src 'none'; style-src 'unsafe-inline'; sandbox";

    public function marketing(
        Request $request,
        MarketingCreative $creative,
        string $format,
        PageBuilderPreviewService $previews,
    ): PageBuilderPreviewResponse {
        $this->authorizeAdmin($request);
        $creativeFormat = MarketingCreativeFormat::tryFrom($format);
        abort_unless($creativeFormat, 404);

        $preview = $previews->marketing($creative, $creativeFormat);

        return $this->response($preview);
    }

    public function mail(
        Request $request,
        MailDocument $document,
        PageBuilderPreviewService $previews,
    ): PageBuilderPreviewResponse {
        $this->authorizeAdmin($request);
        $theme = (string) $request->query('theme', 'light');
        abort_unless(in_array($theme, ['light', 'dark'], true), 404);

        $animated = $request->boolean('animate') && ! $request->boolean('static');
        $preview = $previews->mail(
            $document,
            $request->user(),
            $theme,
            animated: $animated,
            playbackNonce: $animated ? bin2hex(random_bytes(12)) : null,
        );

        return $this->response($preview);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->isAdmin(), 403);
    }

    /** @param array{html: string, width: int, height: int} $preview */
    private function response(array $preview): PageBuilderPreviewResponse
    {
        return new PageBuilderPreviewResponse($preview['html'], 200, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Security-Policy' => self::CSP,
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-PageBuilder-Preview-Height' => (string) $preview['height'],
            'X-PageBuilder-Preview-Width' => (string) $preview['width'],
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }
}
