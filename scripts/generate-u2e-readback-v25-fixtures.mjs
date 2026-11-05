import fs from 'node:fs';
import crypto from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import { parseHTML } from 'linkedom';
import { universalRedactedReadbackFixture, validateUniversalRedactedFixture } from '../resources/js/outlook-addin/u2e-readback-diagnostics.js';

const app = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const folder = path.join(app, 'tests/Fixtures/mail/u2e-readback-v2');
const history = path.join(folder, 'legacy-v23');
const priorPin = '5d612a3a4a5b48f63660774599fb7bc9405e3856517832f1cccd50bf9a064204';
const templatePin = 'ecb6bbe92fbf50b5ecc9f0145894cf524d03545d776df3ff1ff4046a5db38ecf';
const files = ['source.json', 'signature.json', 'rooster.synthetic.html', 'word.synthetic.html', 'manifest.json'];
const json = name => JSON.parse(fs.readFileSync(name, 'utf8'));
const sha = value => crypto.createHash('sha256').update(value).digest('hex');
const writeJson = (name, value) => fs.writeFileSync(name, JSON.stringify(value, null, 2) + '\n');
if (!fs.existsSync(history)) {
    if (json(path.join(folder, 'manifest.json')).templateSha256 !== priorPin) throw new Error('Expected v2.3 before archival');
    fs.mkdirSync(history);
    for (const file of files) fs.copyFileSync(path.join(folder, file), path.join(history, file));
}
if (json(path.join(history, 'manifest.json')).templateSha256 !== priorPin) throw new Error('Historical v2.3 template pin mismatch');
const prior = json(path.join(history, 'source.json'));
const php = String.raw`<?php
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->instance('config',new Illuminate\Config\Repository(['app'=>['url'=>'https://mail.example.test']]));
$descriptor=['sourceHash'=>'3d90dca7fbf2aa7a34e7dd24be92159717e601b8d365fcb4c94957b09592d360','kind'=>'employee',
'identity'=>['name'=>'Fixture person','role'=>'Fixture role'],
'direct'=>[['type'=>'email','value'=>'direct@example.invalid','label'=>'direct@example.invalid'],['type'=>'phone','value'=>'+491705550101','label'=>'+49 170 5550101']],
'company'=>['name'=>'Fixture company','website'=>'https://mail.example.test/','websiteLabel'=>'mail.example.test','street'=>'Musterweg 12','postalCity'=>'10115 Berlin','registration'=>'Fixture legal','confidentiality'=>'Fixture confidentiality'],
'dispatch'=>['email'=>'dispatch@example.invalid','whatsapp'=>'https://wa.me/491601881848','phone'=>'+491601881839','phoneLabel'=>'+49 160 1881839']];
$author=new Illuminate\Support\HtmlString('<p>Guten Tag,</p><p>Fixture author: hier steht der individuelle Nachrichtentext.</p><p>Mit freundlichen Grüßen,<br>Fixture person</p>');
$result=(new App\Support\OutlookAddin\OutlookUniversalPresentation('https://mail.example.test'))->render($descriptor,$author);
$envelope=static fn(string $kind,string $html):array=>['schema'=>1,'kind'=>$kind,'html'=>$html,'version'=>$result['version'],'marker'=>$result['marker'],'header'=>$result['header'],'sourceHash'=>$result['sourceHash'],'media'=>[]];
echo json_encode(['source'=>$envelope('combined',$result['html']),'signature'=>$envelope('signature',$result['signatureHtml']),'templateSha256'=>$result['templateHash']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
`;
const generated = JSON.parse(execFileSync(process.env.U2E_PHP_BINARY || 'C:/xampp/php/php.exe', [], { cwd: app, input: php, encoding: 'utf8' }));
if (generated.templateSha256 !== templatePin) throw new Error('C-K26 template mismatch');
for (const [file, value] of [['source.json', generated.source], ['signature.json', generated.signature]]) writeJson(path.join(folder, file), value);
function removeLine(document) {
    const candidates = [...document.querySelectorAll('td[colspan="5"]')];
    if (candidates.length !== 1 || candidates[0].parentNode.localName !== 'tr') throw new Error('Exactly one historical separator required');
    candidates[0].parentNode.remove();
}
function projectFlatDispatch(document) {
    const panel = [...document.querySelectorAll('table')].find(node => node.getAttribute('width') === '247'
        || /(?:^|;)width:247px(?:;|$)/.test(node.getAttribute('style') || ''));
    const rows = table => [...table.querySelectorAll('tr')].filter(row => row.closest('table') === table);
    const panelRows = rows(panel), head = [...rows(panelRows[1].querySelector('table'))[0].children];
    const actions = [...rows(panelRows[2].querySelector('table'))[0].children];
    if (head.length !== 2 || actions.length !== 5) throw new Error('Expected flat typography roles');
    function replace(node, old, fresh, required = true) {
        const style = node.getAttribute('style') || '';
        if (required && !style.includes(old)) throw new Error('Missing synthetic typography declaration: ' + old);
        if (style.includes(old)) node.setAttribute('style', style.replaceAll(old, fresh));
    }
    for (const cell of head) {
        const style = cell.getAttribute('style') || '';
        if (style.includes('padding:0 0 9px')) replace(cell, 'padding:0 0 9px', 'padding:0 0 4px');
        else replace(cell, 'padding-bottom:9px', 'padding-bottom:4px');
    }
    for (const node of [head[0], ...head[0].querySelectorAll('*')]) {
        replace(node, 'line-height:21px', 'line-height:20px', false);
        replace(node, 'line-height:19px', 'line-height:18px', false);
    }
    for (const node of [head[1], ...head[1].querySelectorAll('*')]) {
        replace(node, 'font-size:38px', 'font-size:34px', false);
        replace(node, 'line-height:40px', 'line-height:36px', false);
    }
    for (const cell of actions) {
        if ((cell.getAttribute('style') || '').includes('padding:11px')) {
            replace(cell, 'padding:11px 0 5px', 'padding:6px 0 4px', false);
            replace(cell, 'padding:11px 8px 5px 0', 'padding:6px 8px 4px 0', false);
        } else {
            replace(cell, 'padding-top:11px', 'padding-top:6px');
            replace(cell, 'padding-bottom:5px', 'padding-bottom:4px');
        }
    }
}
for (const file of ['rooster.synthetic.html', 'word.synthetic.html']) {
    const { document } = parseHTML(fs.readFileSync(path.join(history, file), 'utf8').replaceAll(prior.version, generated.source.version));
    removeLine(document);
    projectFlatDispatch(document);
    fs.writeFileSync(path.join(folder, file), document.toString().trimEnd() + '\n');
}

// Project only the already-redacted C-K24 iOS topology onto the synthetic v2.5
// renderer output. This is a regression fixture, never a native v2.5 probe.
const native = json(path.join(folder, 'ios.ck24-210443.redacted.json'));
const expected = universalRedactedReadbackFixture(generated.signature.html, generated.signature.html);
if (!expected || !validateUniversalRedactedFixture(native)) throw new Error('Valid redacted inputs required');
const oldDocument = parseHTML(native.expectedHtml).document;
const expectedDocument = parseHTML(expected.expectedHtml).document;
const oldVersion = /rt-sig-u2-([0-9a-f]{24})/.exec(native.expectedHtml)[1];
const { document: ios } = parseHTML(native.html.replaceAll(oldVersion, generated.source.version));
removeLine(ios);
projectFlatDispatch(ios);
for (const [selector, attribute] of [['img', 'src'], ['a', 'href']]) {
    const old = [...oldDocument.querySelectorAll(selector)], fresh = [...expectedDocument.querySelectorAll(selector)], actual = [...ios.querySelectorAll(selector)];
    if (old.length !== fresh.length || old.length !== actual.length) throw new Error('Synthetic iOS material order mismatch');
    for (let index = 0; index < old.length; index++) {
        if (old[index].getAttribute(attribute) !== actual[index].getAttribute(attribute)) throw new Error('Captured iOS material changed');
        actual[index].setAttribute(attribute, fresh[index].getAttribute(attribute));
    }
}
const html = ios.toString();
const iosFixture = { schema: 1, format: native.format, html, expectedHtml: expected.expectedHtml, sourceLength: html.length,
    sha256: sha(html), expectedSha256: sha(expected.expectedHtml) };
if (!validateUniversalRedactedFixture(iosFixture)) throw new Error('Synthetic iOS fixture failed the strict redaction validator');
writeJson(path.join(folder, 'ios.synthetic.redacted.json'), iosFixture);

const oldManifest = json(path.join(history, 'manifest.json'));
const names = [...Object.keys(oldManifest.artifacts), ...files.map(file => 'legacy-v23/' + file), 'ios.synthetic.redacted.json'];
const artifacts = {};
for (const file of [...new Set(names)]) {
    const bytes = fs.readFileSync(path.join(folder, file), 'utf8').replaceAll('\r\n', '\n');
    artifacts[file] = { bytes: Buffer.byteLength(bytes), sha256: sha(bytes) };
}
writeJson(path.join(folder, 'manifest.json'), { ...oldManifest, kind: 'synthetic-c-k26-v25-host-serializations', version: generated.source.version,
    previousLineVersion: prior.version, previousLineSource: 'legacy-v23/source.json', templateSha256: templatePin,
    description: 'C-K25+C-K26 v2.5 actual synthetic B1 output; separator removed and approved flatter typography/paddings projected. Word/Rooster are offline structural projections. iOS is a synthetic projection of the previously redacted C-K24 structure; not a native v2.5 acceptance. Historical v2.3 and native C-K24 pairs remain unchanged.',
    iosProjection: { file: 'ios.synthetic.redacted.json', nativeClientProof: false, basis: 'ios.ck24-210443.redacted.json', changes: ['current synthetic marker', 'separator row removed', 'approved v2.5 typography and paddings', 'synthetic material URLs'] }, artifacts });
console.log(JSON.stringify({ version: generated.source.version, templateSha256: templatePin, artifacts: Object.keys(artifacts).length, nativeClientProof: false }));
