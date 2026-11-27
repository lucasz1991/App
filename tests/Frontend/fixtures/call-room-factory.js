import { readFile } from 'node:fs/promises';
import vm from 'node:vm';
// Execute the production controller with a test-only Room; no production
// configuration flag, browser media, network connection, or stylesheet load.
export async function loadCallRoomFactory(overrides = {}) {
    const entry = new URL('../../../resources/js/calls.js', import.meta.url);
    let source = (await readFile(entry, 'utf8')).replace(/^import\s*['"][^'"]+\.css['"];?\s*$/gm, '');
    const imports = {};
    // Execute real helpers in their module environment and replace only the SDK objects under test.
    const namedImport = /^import\s*\{([^}]*)\}\s*from\s*['"]([^'"]+)['"];?/gm;
    for (const match of source.matchAll(namedImport)) {
        const specifier = match[2];
        const exports = await import(specifier.startsWith('.') ? new URL(specifier, entry) : specifier);
        for (const binding of match[1].split(',').map(name => name.trim()).filter(Boolean)) {
            const [name, alias = name] = binding.split(/\s+as\s+/);
            imports[alias] = exports[name];
        }
    }
    source = source.replace(namedImport, '');
    let initialize;
    let factory;
    const windowLike = overrides.window || globalThis.window;
    const context = {
        ...imports,
        window: windowLike,
        document: { addEventListener(_event, callback) { initialize = callback; } },
        AbortController, DOMException, CustomEvent: globalThis.CustomEvent,
        console,
        fetch: (...args) => globalThis.fetch(...args),
        requestAnimationFrame: (...args) => globalThis.requestAnimationFrame?.(...args),
        cancelAnimationFrame: (...args) => globalThis.cancelAnimationFrame?.(...args),
        ...overrides,
    };
    // Preserve the registration wrapper even when the test overrides window.
    context.window = new Proxy(windowLike, {
        get(target, key) {
            if (key === 'Alpine') return { ...target.Alpine, data(_name, value) { factory = value; } };
            return Reflect.get(target, key);
        },
    });
    Object.defineProperty(context, 'navigator', {
        get: () => overrides.navigator || globalThis.navigator,
    });
    vm.runInNewContext(source, context, { filename: 'resources/js/calls.js' });
    initialize();
    return factory;
}
