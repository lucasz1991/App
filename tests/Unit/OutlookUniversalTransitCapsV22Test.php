<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Mail\OutlookUniversalTransitCandidate;
use PHPUnit\Framework\TestCase;

final class OutlookUniversalTransitCapsV22Test extends TestCase
{
    public function test_current_hosts_remain_candidates_without_corner_images(): void
    {
        foreach (['word.synthetic.html', 'rooster.synthetic.html'] as $name) {
            $html = file_get_contents(dirname(__DIR__).'/Fixtures/mail/u2e-readback-v2/'.$name);
            $result = OutlookUniversalTransitCandidate::inspect($html, $this->headers(), true);

            self::assertTrue($result['eligible'], $name);
            self::assertSame(9, $result['candidate']['imageCount']);
            self::assertStringNotContainsString('c01c48d975.png', $html);

            // Eleven images fit neither the previous nor current image family.
            $extra = '<img src="https://example.invalid/a.png"><img src="https://example.invalid/b.png">';
            // Insert inside the signature, immediately before its real logo.
            $mutated = preg_replace('/(<img\b)/', $extra.'$1', $html, 1, $count);
            self::assertSame(1, $count);
            self::assertFalse(OutlookUniversalTransitCandidate::inspect($mutated, $this->headers(), true)['eligible']);
        }
    }

    public function test_historical_corner_image_layout_is_still_only_a_candidate_hint(): void
    {
        $html = file_get_contents(dirname(__DIR__).'/Fixtures/mail/u2e-readback-v2/legacy-c15/word.synthetic.html');
        $result = OutlookUniversalTransitCandidate::inspect($html, $this->headers(), true);

        self::assertTrue($result['eligible']);
        self::assertSame(13, $result['candidate']['imageCount']);
        self::assertArrayNotHasKey('current', $result);
        self::assertArrayNotHasKey('authorized', $result);
    }

    private function headers(): array
    {
        return ['X-RailTime-Template' => 'u2e-'.str_repeat('1', 24)];
    }
}
