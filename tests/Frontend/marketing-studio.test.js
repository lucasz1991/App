import test from 'node:test';
import assert from 'node:assert/strict';

import {
    MARKETING_ARTBOARDS,
    createMarketingBlocks,
    normalizeVariantPayload,
    replaceEndpointToken,
    resolveArtboard,
} from '../../resources/js/marketing-studio.js';

test('marketing artboards keep the exact publishing dimensions', () => {
    assert.deepEqual(MARKETING_ARTBOARDS.story, { label: 'Story', width: 1080, height: 1920 });
    assert.deepEqual(MARKETING_ARTBOARDS.post, { label: 'Post', width: 1080, height: 1080 });
    assert.deepEqual(MARKETING_ARTBOARDS.web, { label: 'Web', width: 1200, height: 630 });
    assert.equal(resolveArtboard('unknown'), MARKETING_ARTBOARDS.story);
});

test('route placeholders are replaced with encoded public ids', () => {
    assert.equal(
        replaceEndpointToken('/marketing/motive/id/varianten/__FORMAT__', '__FORMAT__', 'story'),
        '/marketing/motive/id/varianten/story',
    );
    assert.equal(
        replaceEndpointToken('/marketing/medien/__ASSET__', '__ASSET__', 'a/b'),
        '/marketing/medien/a%2Fb',
    );
});

test('variant refresh accepts server maps and drops unknown formats', () => {
    assert.deepEqual(normalizeVariantPayload({
        variants: {
            story: { builder_data: { pages: [] }, content_hash: 'story-hash', version: 2 },
            post: { builder_data: { pages: [1] }, content_hash: 'post-hash', version: 3 },
            web: { builder_data: { pages: [2] }, content_hash: 'web-hash', version: 4 },
            print: { builder_data: {}, content_hash: 'ignored', version: 1 },
        },
    }), {
        story: { builderData: { pages: [] }, contentHash: 'story-hash', version: 2 },
        post: { builderData: { pages: [1] }, contentHash: 'post-hash', version: 3 },
        web: { builderData: { pages: [2] }, contentHash: 'web-hash', version: 4 },
    });
});

test('RailTime block set uses backend bindings and a local scan-ready QR image', async () => {
    const blocks = await createMarketingBlocks('/rt-brand/rt-logo.svg', 'https://www.rail-time.de/de/karriere');
    const definitions = Object.fromEntries(blocks.map((block) => [block.id, block.definition.content]));

    assert.equal(blocks.length, 11);
    assert.match(definitions['rt-marketing-headline'], /data-rt-binding="title"/);
    assert.match(definitions['rt-marketing-facts'], /data-rt-binding-facts="facts"/);
    assert.match(definitions['rt-marketing-tasks'], /data-rt-binding-list="tasks"/);
    assert.match(definitions['rt-marketing-contact'], /data-rt-binding="contact_phone"/);
    assert.match(definitions['rt-marketing-contact'], /data-rt-binding="contact_email"/);
    assert.match(definitions['rt-marketing-cta'], /data-rt-binding-href="cta_url"/);

    const qr = definitions['rt-marketing-qr'];
    assert.match(qr, /^<img /);
    assert.match(qr, /src="data:image\/png;base64,iVBOR/);
    assert.match(qr, /alt="QR-Code zur Bewerbung"/);
    assert.doesNotMatch(qr, /google|quickchart|api\./i);
});

test('adapter explicitly disables Joomla web defaults and fallback projects', async () => {
    const source = await import('node:fs/promises')
        .then(({ readFile }) => readFile(new URL('../../resources/js/marketing-studio.js', import.meta.url), 'utf8'));

    assert.match(source, /useStudioWebDefaults:\s*false/);
    assert.match(source, /allowFallbackProject:\s*false/);
    assert.match(source, /document\.addEventListener\('livewire:navigating', destroyMarketingStudio\)/);
    assert.match(source, /instance\?\.destroy\?\.\(\)/);
});
