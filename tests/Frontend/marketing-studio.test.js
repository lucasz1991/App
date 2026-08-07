import test from 'node:test';
import assert from 'node:assert/strict';

import {
    applySavedVariant,
    completedRenderDownloadUrl,
    createStudioBootGuard,
    MARKETING_ARTBOARDS,
    createMarketingBlocks,
    normalizeVariantPayload,
    projectForVariant,
    renderRequestIsCurrent,
    replaceEndpointToken,
    resolveArtboard,
    serializeSharedData,
    syncQrCode,
} from '../../resources/js/marketing-studio.js';

test('simultaneous initial events reuse one LMZ studio boot', async () => {
    const guard = createStudioBootGuard();
    const workspace = {};
    let createCount = 0;
    let destroyCount = 0;
    let resolveStudio;
    const createStudio = () => {
        createCount += 1;

        return new Promise((resolve) => {
            resolveStudio = () => resolve({
                destroy() {
                    destroyCount += 1;
                },
            });
        });
    };

    const domReadyBoot = guard.boot(workspace, createStudio);
    const livewireBoot = guard.boot(workspace, createStudio);
    await Promise.resolve();

    assert.equal(createCount, 1);
    resolveStudio();
    const [firstStudio, secondStudio] = await Promise.all([domReadyBoot, livewireBoot]);
    assert.equal(firstStudio, secondStudio);
    assert.equal(guard.getActive(), firstStudio);
    assert.equal(destroyCount, 0);

    guard.destroy();
    assert.equal(destroyCount, 1);
    assert.equal(guard.getActive(), null);
});

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

test('shared form bracket names serialize into the backend shared_content contract', () => {
    const fields = [
        ['title', ' Wagenmeister Kampagne '],
        ['shared_content[kicker]', ' Komm ins Team '],
        ['shared_content[facts][0][value]', '60+'],
        ['shared_content[facts][0][label]', 'Wagenmeister'],
        ['shared_content[tasks][]', 'Technische Untersuchung'],
        ['shared_content[tasks][]', '  '],
        ['shared_content[cta_url]', 'https://www.rail-time.de/de/karriere'],
    ];
    const data = {
        entries: () => fields[Symbol.iterator](),
        get: (name) => fields.find(([field]) => field === name)?.[1] ?? null,
    };

    assert.deepEqual(serializeSharedData(data), {
        title: 'Wagenmeister Kampagne',
        shared_content: {
            kicker: 'Komm ins Team',
            facts: [{ value: '60+', label: 'Wagenmeister' }],
            tasks: ['Technische Untersuchung'],
            cta_url: 'https://www.rail-time.de/de/karriere',
        },
    });
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
        story: { builderData: { pages: [] }, css: '', contentHash: 'story-hash', version: 2 },
        post: { builderData: { pages: [1] }, css: '', contentHash: 'post-hash', version: 3 },
        web: { builderData: { pages: [2] }, css: '', contentHash: 'web-hash', version: 4 },
    });
});

test('a saved CSS response survives format reloads and remains the next save fallback', () => {
    const variant = {
        builderData: { pages: [{ component: '<p>Story</p>' }], styles: [] },
        html: '<p>Alt</p>',
        css: '.headline{color:#111}',
        contentHash: 'old-hash',
        version: 1,
    };
    const submitted = {
        project: { pages: [{ component: '<p>Story</p>' }], styles: [] },
        html: '<p onclick="bad()">Nicht bereinigt</p>',
        css: '.headline{color:#e4002b}',
    };

    applySavedVariant(variant, {
        builder_data: submitted.project,
        html: '<p>Serverbereinigt</p>',
        css: '.headline{color:#c90025}',
        content_hash: 'new-hash',
        version: 2,
    }, submitted);

    assert.equal(variant.html, '<p>Serverbereinigt</p>');
    assert.equal(variant.css, '.headline{color:#c90025}');

    let parsedCss = '';
    const reloaded = projectForVariant(variant, (css) => {
        parsedCss = css;
        return [{ selectors: ['.headline'], style: { color: '#c90025' } }];
    });
    assert.equal(parsedCss, '.headline{color:#c90025}');
    assert.equal(reloaded.styles[0].style.color, '#c90025');

    applySavedVariant(variant, {}, {
        project: reloaded,
        html: variant.html,
        css: variant.css,
    });
    assert.equal(variant.css, '.headline{color:#c90025}');
    assert.equal(variant.html, '<p>Serverbereinigt</p>');
});

test('a story render response cannot overwrite post status after a format switch', async () => {
    const storyRequest = { requestId: 4, format: 'story' };
    assert.equal(renderRequestIsCurrent({
        ...storyRequest,
        activeRequestId: 4,
        currentFormat: 'story',
    }), true);

    const postState = { activeRequestId: 5, currentFormat: 'post' };
    let visibleStatus = 'Noch kein Export für dieses Format erstellt.';
    if (renderRequestIsCurrent({ ...storyRequest, ...postState })) {
        visibleStatus = `${resolveArtboard(storyRequest.format).label}-PNG ist bereit.`;
    }

    assert.equal(visibleStatus, 'Noch kein Export für dieses Format erstellt.');
    assert.equal(renderRequestIsCurrent({
        requestId: 5,
        activeRequestId: 5,
        format: 'post',
        currentFormat: 'post',
    }), true);

    const source = await import('node:fs/promises')
        .then(({ readFile }) => readFile(new URL('../../resources/js/marketing-studio.js', import.meta.url), 'utf8'));
    assert.match(source, /json:\s*\{ format: exportFormat \}/);
    assert.match(source, /resolveArtboard\(format\)\.label/);
    assert.match(source, /renderAbortController\?\.abort\(\)/);
    assert.match(source, /renderTimers\.forEach/);
});

test('an explicitly stale completed render never exposes a fallback download link', () => {
    const fallback = '/administrator/marketing/render/render-id/download';

    assert.equal(completedRenderDownloadUrl({ download_url: null }, fallback), null);
    assert.equal(completedRenderDownloadUrl({ download_url: '' }, fallback), null);
    assert.equal(completedRenderDownloadUrl({ download_url: '   ' }, fallback), null);
    assert.equal(completedRenderDownloadUrl({ download_url: '/fresh/render.png' }, fallback), '/fresh/render.png');
    assert.equal(completedRenderDownloadUrl({}, fallback), fallback);
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

test('the initial block set stays loadable with an empty CTA and can later receive a scan-ready QR', async () => {
    const { PNG } = await import('pngjs');

    for (const emptyCta of ['', '   ']) {
        const blocks = await createMarketingBlocks('/rt-brand/rt-logo.svg', emptyCta);
        const qr = blocks.find((block) => block.id === 'rt-marketing-qr')?.definition.content || '';
        const source = qr.match(/src="([^"]+)"/)?.[1] || '';
        const neutralQr = PNG.sync.read(Buffer.from(source.split(',', 2)[1], 'base64'));

        assert.match(qr, /data-rt-qr-value=""/);
        assert.match(qr, /alt="Kein QR-Code: Zieladresse fehlt"/);
        assert.deepEqual([neutralQr.width, neutralQr.height, neutralQr.data[3]], [1, 1, 0]);
    }

    const attributes = {
        'data-rt-qr-value': '',
        src: 'data:image/png;base64,neutral',
        alt: 'Kein QR-Code: Zieladresse fehlt',
    };
    const component = {
        getAttributes: () => attributes,
        addAttributes(next) {
            Object.assign(attributes, next);
        },
    };
    const editor = {
        DomComponents: {
            getWrapper: () => ({
                find: (selector) => selector === '[data-rt-qr-binding="cta_url"]' ? [component] : [],
            }),
        },
    };

    assert.equal(await syncQrCode(editor, 'https://www.rail-time.de/de/karriere'), true);
    assert.match(attributes.src, /^data:image\/png;base64,iVBOR/);
    assert.equal(attributes.alt, 'QR-Code zur Bewerbung');
});

test('an existing QR image is neutralized for an empty CTA and regenerated locally afterwards', async () => {
    const attributes = {
        'data-rt-qr-value': 'https://www.rail-time.de/de/alt',
        src: 'data:image/png;base64,old',
    };
    const component = {
        getAttributes: () => attributes,
        addAttributes(next) {
            Object.assign(attributes, next);
        },
    };
    const editor = {
        DomComponents: {
            getWrapper: () => ({
                find: (selector) => selector === '[data-rt-qr-binding="cta_url"]' ? [component] : [],
            }),
        },
    };

    assert.equal(await syncQrCode(editor, ''), true);
    assert.equal(attributes['data-rt-qr-value'], '');
    assert.match(attributes.src, /^data:image\/png;base64,/);
    assert.notEqual(attributes.src, 'data:image/png;base64,old');
    const { PNG } = await import('pngjs');
    const neutralQr = PNG.sync.read(Buffer.from(attributes.src.split(',', 2)[1], 'base64'));
    assert.deepEqual([neutralQr.width, neutralQr.height, neutralQr.data[3]], [1, 1, 0]);
    assert.equal(attributes.alt, 'Kein QR-Code: Zieladresse fehlt');
    assert.equal(await syncQrCode(editor, ''), false);

    assert.equal(await syncQrCode(editor, 'https://www.rail-time.de/de/karriere'), true);
    assert.equal(attributes['data-rt-qr-value'], 'https://www.rail-time.de/de/karriere');
    assert.match(attributes.src, /^data:image\/png;base64,iVBOR/);
    assert.equal(attributes.alt, 'QR-Code zur Bewerbung');
    assert.equal(await syncQrCode(editor, 'https://www.rail-time.de/de/karriere'), false);
});

test('adapter explicitly disables Joomla web defaults and fallback projects', async () => {
    const source = await import('node:fs/promises')
        .then(({ readFile }) => readFile(new URL('../../resources/js/marketing-studio.js', import.meta.url), 'utf8'));

    assert.match(source, /useStudioWebDefaults:\s*false/);
    assert.match(source, /allowFallbackProject:\s*false/);
    assert.match(source, /document\.addEventListener\('livewire:navigating', destroyMarketingStudio\)/);
    assert.match(source, /instance\?\.destroy\?\.\(\)/);
    assert.match(source, /frame\.dataset\.readOnly = readOnly \? 'true' : 'false'/);
    assert.match(source, /\[data-lmz-action="assets"\]/);
    assert.match(source, /request\.expected_hashes = Object\.fromEntries/);
});
