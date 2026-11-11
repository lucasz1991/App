import fs from 'node:fs';
import crypto from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import { parseHTML } from 'linkedom';
import { universalRedactedReadbackFixture, validateUniversalRedactedFixture } from '../resources/js/outlook-addin/u2e-readback-diagnostics.js';

const app = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const folder = path.join(app, 'tests/Fixtures/mail/u2e-readback-v2'), history = path.join(folder, 'legacy-v27');
const priorPin = '1bfd6e33fbc15a5e278ce29cf039721cedd9b4f0a2a5506c4012df3d5483207d';
const templatePin = '0201650e3985cf95b7ef84d112fede5d8338017f6d725a764906da1a99e1c5dc';
const files = ['source.json', 'signature.json', 'rooster.synthetic.html', 'word.synthetic.html', 'ios.synthetic.redacted.json', 'manifest.json'];
const json = name => JSON.parse(fs.readFileSync(name, 'utf8'));
const sha = value => crypto.createHash('sha256').update(value).digest('hex');
const writeJson = (name, value) => fs.writeFileSync(name, JSON.stringify(value, null, 2) + '\n');
if (!fs.existsSync(history)) {
    if (json(path.join(folder, 'manifest.json')).templateSha256 !== priorPin) throw new Error('Expected v27 before archival');
    fs.mkdirSync(history);
    for (const file of files) fs.copyFileSync(path.join(folder, file), path.join(history, file));
}
if (json(path.join(history, 'manifest.json')).templateSha256 !== priorPin) throw new Error('Historical v27 pin mismatch');
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
if (generated.templateSha256 !== templatePin) throw new Error('Left-aligned template mismatch');
for (const [file, value] of [['source.json', generated.source], ['signature.json', generated.signature]]) writeJson(path.join(folder, file), value);
function inFlowProjection(html) {
    const current = html.replaceAll(prior.version, generated.source.version);
    let removed = 0;
    const projected = current.replace(/<table\b[^>]*>/i, tag => tag.replace(/\s+align=(['"])left\1/i, () => { removed++; return ''; }));
    if (removed !== 1) throw new Error('Historical outer table must have the explicit wrapping attribute');
    return projected;
}
for (const file of ['rooster.synthetic.html', 'word.synthetic.html']) {
    fs.writeFileSync(path.join(folder, file), inFlowProjection(fs.readFileSync(path.join(history, file), 'utf8')));
}
const previousIos = json(path.join(history, 'ios.synthetic.redacted.json'));
if (!validateUniversalRedactedFixture(previousIos)) throw new Error('Valid historical synthetic iOS required');
const expected = universalRedactedReadbackFixture(generated.signature.html, generated.signature.html);
if (!expected) throw new Error('Current redacted signature required');
const html = inFlowProjection(previousIos.html).trimEnd();
const ios = { schema: 1, format: previousIos.format, html, expectedHtml: expected.expectedHtml, sourceLength: html.length,
    sha256: sha(html), expectedSha256: sha(expected.expectedHtml) };
if (!validateUniversalRedactedFixture(ios)) throw new Error('Invalid synthetic iOS left projection');
writeJson(path.join(folder, 'ios.synthetic.redacted.json'), ios);
const oldManifest = json(path.join(history, 'manifest.json'));
const names = [...Object.keys(oldManifest.artifacts), ...files.map(file => 'legacy-v27/' + file)];
const artifacts = {};
for (const file of [...new Set(names)]) {
    const bytes = fs.readFileSync(path.join(folder, file), 'utf8').replaceAll('\r\n', '\n');
    artifacts[file] = { bytes: Buffer.byteLength(bytes), sha256: sha(bytes) };
}
writeJson(path.join(folder, 'manifest.json'), { ...oldManifest, kind: 'synthetic-v28-in-flow-host-serializations', version: generated.source.version,
    previousInFlowVersion: prior.version, previousInFlowSource: 'legacy-v27/source.json', templateSha256: templatePin,
    description: 'Current synthetic renderer output removes only the outer legacy TABLE align attribute that can enable Word text wrapping. Word/Rooster/iOS fixtures remove that attribute and change current ownership markers from exact archived v27 inputs. No native client or affected-message HTML proof is claimed. Historical inputs remain immutable.',
    alignmentProjection: { align: null, margin: '0 auto 0 0', float: 'none', textAlign: 'left', changes: ['remove outer TABLE align attribute and change current marker'], nativeClientProof: false },
    iosProjection: { file: 'ios.synthetic.redacted.json', nativeClientProof: false, basis: 'legacy-v27/ios.synthetic.redacted.json', nativeAncestor: 'ios.ck24-210443.redacted.json', changes: ['remove outer TABLE align attribute and change current marker'] }, artifacts });
console.log(JSON.stringify({ version: generated.source.version, templateSha256: templatePin, artifacts: Object.keys(artifacts).length, nativeClientProof: false }));
