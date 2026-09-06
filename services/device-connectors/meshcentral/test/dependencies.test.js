import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { test } from 'node:test';

const require = createRequire(import.meta.url);
const qs = require('qs');

test('MeshCentral and its HTTP parsers resolve the explicitly reviewed versions', () => {
  assert.equal(require('meshcentral/package.json').version, '1.2.5');
  for (const dependency of ['express', 'body-parser']) {
    const dependencyRequire = createRequire(require.resolve(`${dependency}/package.json`));
    assert.equal(dependencyRequire('qs/package.json').version, '6.16.0');
  }
});

test('qs safely serializes parsed constructor/isBuffer values', () => {
  // Regression coverage for GHSA-4mjr-xmp4-gh2g; no network or large payload.
  const parsed = qs.parse('x[constructor][isBuffer]=value', { plainObjects: true });
  assert.doesNotThrow(() => qs.stringify(parsed));
});

test('qs enforces comma-array limits for bracketed keys', () => {
  // Regression coverage for GHSA-x5fp-wj9c-mxmx using only four elements.
  assert.throws(() => qs.parse('a[]=1,2,3,4', {
    comma: true,
    arrayLimit: 3,
    throwOnLimitExceeded: true,
  }), RangeError);
});

test('normal nested query and form fields retain their meaning', () => {
  const fields = { action: 'login', options: { locale: 'de' }, scopes: ['device', 'support'] };
  assert.deepEqual(qs.parse(qs.stringify(fields)), fields);
});
