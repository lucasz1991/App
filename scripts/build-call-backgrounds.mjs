import { createHash } from 'node:crypto';
import { access, cp, mkdir, readFile, rename, writeFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { build } from 'esbuild';

// All browser requests for the SDK, WASM, model and background stay on RailTime.
const app = fileURLToPath(new URL('../', import.meta.url));
const sdk = resolve(app, 'node_modules/@livekit/track-processors');
const runtime = resolve(app, 'node_modules/@mediapipe/tasks-vision');
const out = resolve(app, 'public/vendor/call-backgrounds/0.7.2');
const model = resolve(out, 'selfie_segmenter.tflite');
const modelUrl = 'https://storage.googleapis.com/mediapipe-models/image_segmenter/selfie_segmenter/float16/1/selfie_segmenter.tflite';
const modelDigest = '191ac9529ae506ee0beefa6b2c945a172dab9d07d1e802a290a4e4038226658b';
await access(resolve(sdk, 'dist/index.mjs'));
await mkdir(out, { recursive: true });
const valid = bytes => createHash('sha256').update(bytes).digest('hex') === modelDigest;
let bytes = await readFile(model).catch(() => null);
if (!bytes || !valid(bytes)) {
    const response = await fetch(modelUrl, { signal: AbortSignal.timeout(30000) });
    if (!response.ok) throw new Error('Camera segmentation model download failed: ' + response.status);
    bytes = Buffer.from(await response.arrayBuffer());
    if (!valid(bytes)) throw new Error('Unexpected camera segmentation model checksum');
    await writeFile(model + '.tmp', bytes);
    await rename(model + '.tmp', model);
}
await cp(resolve(runtime, 'wasm'), resolve(out, 'wasm'), { recursive: true });
const bundle = await build({
    absWorkingDir: app,
    stdin: { contents: "export { BackgroundProcessor, supportsBackgroundProcessors } from '@livekit/track-processors'", resolveDir: app },
    format: 'esm', platform: 'browser', bundle: true, minify: true, write: false,
    legalComments: 'inline',
});
await writeFile(resolve(out, 'processor.tmp'), bundle.outputFiles[0].contents);
await rename(resolve(out, 'processor.tmp'), resolve(out, 'processor.js'));
