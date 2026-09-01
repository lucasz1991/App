import { mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { build } from 'vite';

const projectRoot = fileURLToPath(new URL('..', import.meta.url));
const sourceDirectory = path.join(projectRoot, 'resources', 'js', 'outlook-addin');
const outputDirectory = path.resolve(
    projectRoot,
    process.env.OUTLOOK_ADDIN_OUT_DIR || path.join('public', 'outlook-addin'),
);
const argumentConfigUrl = process.argv
    .find((argument) => argument.startsWith('--config-url='))
    ?.slice('--config-url='.length);
const configUrl = String(
    argumentConfigUrl
        || process.env.OUTLOOK_ADDIN_CONFIG_URL
        || 'https://app.rail-time.de/outlook-addin/config.json',
).trim();

function assertAbsoluteHttpsUrl(value) {
    let url;

    try {
        url = new URL(value);
    } catch {
        throw new Error(
            'OUTLOOK_ADDIN_CONFIG_URL or --config-url must contain the absolute HTTPS config endpoint.',
        );
    }

    if (url.protocol !== 'https:') {
        throw new Error('The Outlook add-in config endpoint must use HTTPS.');
    }

    return url.toString();
}

const resolvedConfigUrl = assertAbsoluteHttpsUrl(configUrl);
const entries = ['runtime', 'taskpane'];

await mkdir(outputDirectory, { recursive: true });

for (let index = 0; index < entries.length; index += 1) {
    const name = entries[index];

    await build({
        configFile: false,
        publicDir: false,
        define: {
            __RAILTIME_OUTLOOK_CONFIG_URL__: JSON.stringify(resolvedConfigUrl),
        },
        build: {
            target: 'es2016',
            outDir: outputDirectory,
            emptyOutDir: index === 0,
            assetsInlineLimit: Number.MAX_SAFE_INTEGER,
            cssCodeSplit: false,
            sourcemap: false,
            minify: 'esbuild',
            modulePreload: false,
            reportCompressedSize: true,
            lib: {
                entry: path.join(sourceDirectory, `${name}.js`),
                name: name === 'runtime' ? 'RailTimeOutlookRuntime' : 'RailTimeOutlookTaskpane',
                formats: ['iife'],
                fileName: () => `${name}.js`,
            },
            rollupOptions: {
                output: {
                    inlineDynamicImports: true,
                    generatedCode: 'es2015',
                },
            },
        },
        logLevel: 'info',
    });
}

console.log(`Outlook add-in bundles written to ${outputDirectory}`);
