<?php

namespace App\Http\Responses;

use Illuminate\Http\Response;

/**
 * Keeps the sandboxed PageBuilder preview immutable after construction.
 *
 * Livewire injects its runtime during Laravel's RequestHandled event. That is
 * useful for application pages, but would add scripts and network requests to
 * this deliberately script-free iframe document. Sealing the successful HTML
 * response at the response boundary also remains safe in long-running workers
 * where Livewire's request state may otherwise outlive an earlier component.
 */
final class PageBuilderPreviewResponse extends Response
{
    private bool $sealed = false;

    private string $previewHtml = '';

    public function __construct(string $content, int $status = 200, array $headers = [])
    {
        parent::__construct($content, $status, $headers);

        $this->previewHtml = $this->getContent();
        $this->sealed = true;
    }

    public function setContent(mixed $content): static
    {
        if ($this->sealed && is_string($content) && $content !== $this->previewHtml) {
            return $this;
        }

        return parent::setContent($content);
    }
}
