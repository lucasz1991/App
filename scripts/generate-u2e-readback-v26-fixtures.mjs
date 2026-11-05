import fs from 'node:fs';
import crypto from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import { parseHTML } from 'linkedom';
import { universalRedactedReadbackFixture, validateUniversalRedactedFixture } from '../resources/js/outlook-addin/u2e-readback-diagnostics.js';

const app = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const folder = path.join(app, 'tests/Fixtures/mail/u2e-readback-v2'), history = path.join(folder, 'legacy-v25');
const priorPin = 'ecb6bbe92fbf50b5ecc9f0145894cf524d03545d776df3ff1ff4046a5db38ecf';
const templatePin = 'aed4ce9baade0a1a4411c6b72da36b893bdf6134262f2367a55f44097ffe5e09';
const files = ['source.json', 'signature.json', 'rooster.synthetic.html', 'word.synthetic.html', 'ios.synthetic.redacted.json', 'manifest.json'];
const json = name => JSON.parse(fs.readFileSync(name, 'utf8'));
const sha = value => crypto.createHash('sha256').update(value).digest('hex');
const writeJson = (name, value) => fs.writeFileSync(name, JSON.stringify(value, null, 2) + '\n');
if (!fs.existsSync(history)) {
    if (json(path.join(folder, 'manifest.json')).templateSha256 !== priorPin) throw new Error('Expected LIVE v2.5 before archival');
    fs.mkdirSync(history);
    for (const file of files) fs.copyFileSync(path.join(folder, file), path.join(history, file));
}
if (json(path.join(history, 'manifest.json')).templateSha256 !== priorPin) throw new Error('Historical v2.5 pin mismatch');
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
$author=new Illuminate\Support\HtmlString('<p>Fixture author: hier steht der individuelle Nachrichtentext.</p>');
$options=['showGreeting'=>true,'greeting'=>'Guten Tag,','showFarewell'=>true,'farewell'=>'Mit freundlichen Grüßen,','farewellName'=>true];
$renderer=new App\Support\OutlookAddin\OutlookUniversalPresentation('https://mail.example.test');
$result=$renderer->render($descriptor,$author,$options);
$authoredName='<p>Authored name: Fixture person</p>';
if(!str_contains($renderer->render($descriptor,new Illuminate\Support\HtmlString($authoredName),$options)['html'],$authoredName))throw new RuntimeException('Authored name bytes must remain opaque');
$envelope=static fn(string $kind,string $html):array=>['schema'=>1,'kind'=>$kind,'html'=>$html,'version'=>$result['version'],'marker'=>$result['marker'],'header'=>$result['header'],'sourceHash'=>$result['sourceHash'],'media'=>[]];
echo json_encode(['source'=>$envelope('combined',$result['html']),'signature'=>$envelope('signature',$result['signatureHtml']),'templateSha256'=>$result['templateHash']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
`;
const generated = JSON.parse(execFileSync(process.env.U2E_PHP_BINARY || 'C:/xampp/php/php.exe', [], { cwd: app, input: php, encoding: 'utf8' }));
if (generated.templateSha256 !== templatePin) throw new Error('C-K27 template mismatch');
const ownRows = table => [...table.querySelectorAll('tr')].filter(row => row.closest('table') === table);
const authorCell = document => ownRows(document.querySelector('table[width="1040"]'))[0].querySelector('td');
const currentAuthor = authorCell(parseHTML(generated.source.html).document);
if (currentAuthor.textContent.includes('Fixture person') || currentAuthor.querySelectorAll('p').length !== 3) throw new Error('Generated farewell must have no name line');
for (const [file, value] of [['source.json', generated.source], ['signature.json', generated.signature]]) writeJson(path.join(folder, file), value);
for (const file of ['rooster.synthetic.html', 'word.synthetic.html']) {
    const document = parseHTML(fs.readFileSync(path.join(history, file), 'utf8').replaceAll(prior.version, generated.source.version)).document;
    const cell = authorCell(document), style = cell.getAttribute('style') || '';
    if (!style.includes('padding:32px 40px 30px')) throw new Error('Expected prior desktop author spacing');
    cell.setAttribute('style', style.replace('padding:32px 40px 30px', 'padding:32px 40px 48px'));
    // This is an explicit offline projection of the newly generated wording,
    // not a claim about native Word/Rooster reserialization of the author block.
    cell.innerHTML = currentAuthor.innerHTML;
    fs.writeFileSync(path.join(folder, file), document.toString().trimEnd() + '\n');
}
const previousIos = json(path.join(history, 'ios.synthetic.redacted.json'));
if (!validateUniversalRedactedFixture(previousIos)) throw new Error('Valid historical synthetic iOS required');
const expected = universalRedactedReadbackFixture(generated.signature.html, generated.signature.html);
if (!expected) throw new Error('Current redacted signature required');
// Only the synthetic signature marker changes; the native input files stay
// immutable and the signature has no author row or new spacing to simulate.
const html = previousIos.html.replaceAll(prior.version, generated.source.version);
const ios = { schema: 1, format: previousIos.format, html, expectedHtml: expected.expectedHtml, sourceLength: html.length,
    sha256: sha(html), expectedSha256: sha(expected.expectedHtml) };
if (!validateUniversalRedactedFixture(ios)) throw new Error('Invalid current synthetic iOS projection');
writeJson(path.join(folder, 'ios.synthetic.redacted.json'), ios);
const oldManifest = json(path.join(history, 'manifest.json'));
const names = [...Object.keys(oldManifest.artifacts), ...files.map(file => 'legacy-v25/' + file)];
const artifacts = {};
for (const file of [...new Set(names)]) {
    const bytes = fs.readFileSync(path.join(folder, file), 'utf8').replaceAll('\r\n', '\n');
    artifacts[file] = { bytes: Buffer.byteLength(bytes), sha256: sha(bytes) };
}
writeJson(path.join(folder, 'manifest.json'), { ...oldManifest, kind: 'synthetic-c-k27-v26-host-serializations', version: generated.source.version,
    previousSpacingVersion: prior.version, previousSpacingSource: 'legacy-v25/source.json', templateSha256: templatePin,
    description: 'C-K27 v2.6 actual synthetic renderer output with generated greeting/farewell, no generated name and 48px author bottom padding. Word/Rooster wording and spacing are explicit offline projections. iOS is the previous synthetic redacted signature projection with current marker only. No native v2.6 acceptance. LIVE v2.5 archive and authentic C-K24 inputs remain unchanged.',
    wordingProjection: { generatedBy: 'OutlookUniversalPresentation::render', showGreeting: true, showFarewell: true, requestedFarewellName: true, appliedFarewellName: false, authoredNameBytesPreserved: true },
    iosProjection: { file: 'ios.synthetic.redacted.json', nativeClientProof: false, basis: 'legacy-v25/ios.synthetic.redacted.json', nativeAncestor: 'ios.ck24-210443.redacted.json', changes: ['current synthetic signature marker'] }, artifacts });
console.log(JSON.stringify({ version: generated.source.version, templateSha256: templatePin, artifacts: Object.keys(artifacts).length, nativeClientProof: false }));
