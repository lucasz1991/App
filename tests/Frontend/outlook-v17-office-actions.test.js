import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import { managedTemplateContract, standaloneSignatureContract, fluidLayoutContract } from '../../resources/js/outlook-addin/managed-template.js';
import { prepareNativeLayoutRestore, verifyFluidLayoutMaterial } from '../../resources/js/outlook-addin/native-layout-restore.js';
import { mobileV17OfficeWire } from '../../resources/js/outlook-addin/v17-office-wire.js';
import { signatureHostProfileIsCurrent, standaloneSignatureLayoutIsCurrent } from '../../resources/js/outlook-addin/compose-template.js';
const fixture = JSON.parse(await readFile(new URL('../Fixtures/mail/outlook-v17-compact-reference/v17-source.json', import.meta.url), 'utf8'));
const mobile = JSON.parse(await readFile(new URL('../Fixtures/mail/outlook-mobile-reference/mobile-reference-v1.json', import.meta.url), 'utf8'));
const configured = JSON.parse(await readFile(new URL('../Fixtures/mail/outlook-v17-office-actions/configured-default.json', import.meta.url), 'utf8'));
const host = profile => ({ context: { platform: profile === 'M' ? 'OfficeOnline' : 'PC', mailbox: { diagnostics: { hostName: profile === 'M' ? 'OutlookWebApp' : 'Outlook' } } } });
const canonical = (html, kind) => kind === 'combined' ? managedTemplateContract(html) : standaloneSignatureContract(html);
const contract = (html, plan) => ({ ...fluidLayoutContract(html), sourceLogoUrl: plan.sourceLogoUrl });

for (const profile of ['M', 'B']) for (const [kind, input] of Object.entries(configured.source)) test('actual configured PHP V17 ' + kind + '/' + profile + ' proves exact action geometry and default white number asset independently from canonical CSS', () => {
    const plan = prepareNativeLayoutRestore(input, canonical(input, kind), '83'.repeat(16), host(profile));
    assert.equal(plan.officeActionsWire, true); assert.equal(plan.defaultDispatchPhoneImage, true);
    const material = verifyFluidLayoutMaterial(plan.html, contract(plan.html, plan));
    assert.equal(material.officeActionsWire, true); assert.equal(material.defaultDispatchPhoneImage, true);
    assert.match(plan.html, /width:215px!important;max-width:100%;table-layout:auto!important/);
    for (const width of [75, 32, 108]) assert.match(plan.html, new RegExp('width:' + width + 'px!important'));
    assert.equal((plan.html.match(/\/m\/ef89fd23c6\.png/g) || []).length, 1);
    const image = /<img\b[^>]*ef89fd23c6\.png[^>]*>/.exec(plan.html)[0];
    assert.match(image, /width="108" height="20" alt="\+49 160 1881839"/); assert.doesNotMatch(image, /max-width/);
    for (const [before, after] of [['width:215px!important', 'width:216px!important'], ['width:75px!important', 'width:1px!important'],
        ['table-layout:auto!important', 'table-layout:fixed!important'], ['ef89fd23c6.png', 'ef89fd23c7.png'], ['height="20"', 'height="21"'],
        ['alt="+49 160 1881839"', 'alt="+49 160 1881830"'], ['white-space:nowrap!important', 'white-space:normal!important']]) {
        const changed = plan.html.replace(before, after); assert.notEqual(changed, plan.html);
        assert.throws(() => verifyFluidLayoutMaterial(changed, contract(changed, plan)), before);
    }
    const foreignOrigin = plan.html.replace(image, image.replace('https://mail.example.test/', 'https://foreign.invalid/'));
    assert.throws(() => verifyFluidLayoutMaterial(foreignOrigin, contract(foreignOrigin, plan)));
    const offset = plan.html.lastIndexOf('href="tel:+491601881839"');
    const changedHref = plan.html.slice(0, offset) + plan.html.slice(offset).replace('href="tel:+491601881839"', 'href="tel:+491601881830"');
    assert.throws(() => verifyFluidLayoutMaterial(changedHref, contract(changedHref, plan)));
});

function wrapperShape(html, plan, name) {
    const c = contract(html, plan), nodes = c.elements;
    const wrapper = nodes.map((node,index) => ({ node,index })).find(v => (v.node.attrs.class || '').split(/\s+/).includes(name));
    assert.ok(wrapper);
    const direct = index => nodes.map((node,index) => ({node,index})).filter(v => v.node.parent === index);
    const body = direct(wrapper.index)[0], row = direct(body.index)[0], cells = direct(row.index);
    return { ...wrapper, nodes, cells, images: nodes.filter((node,index) => node.tag === 'img' && (()=>{for(let p=index;Number.isInteger(p);p=nodes[p]?.parent)if(p===wrapper.index)return true;return false;})()) };
}

test('new desktop dispatch keeps Mail, WhatsApp and phone in physical Word cells with exact independent default/generic spacing', () => {
    for (const [source, widths] of [[configured.source.standalone,[75,32,108]], [fixture.source.standalone,[62,32,121]]]) {
        const plan = prepareNativeLayoutRestore(source, canonical(source,'standalone'), '86'.repeat(16),host('B'));
        const pair = wrapperShape(plan.html,plan,'rt-v17-action-pair'), phone = wrapperShape(plan.html,plan,'rt-v17-phone-action');
        assert.deepEqual(pair.cells.map(v=>Number(v.node.attrs.width)),[24,8,24]); assert.equal(pair.images.length,2);
        assert.deepEqual(phone.cells.map(v=>Number(v.node.attrs.width)),[2,24,6]); assert.equal(phone.images.length,1);
        for(const image of [...pair.images,...phone.images]) {assert.equal(image.attrs.width,'24');assert.equal(image.attrs.height,'24');}
        assert.match(plan.html,/margin-left:0px;/); assert.match(plan.html,/padding-left:0!important;text-align:left!important/);
        const nodes=contract(plan.html,plan).elements, action=nodes.findIndex(n=>(n.attrs.class||'').includes('rt-v17-dispatch-actions'));
        const body=nodes.findIndex(n=>n.parent===action),row=nodes.findIndex(n=>n.parent===body),cells=nodes.filter(n=>n.parent===row);
        assert.deepEqual(cells.map(n=>Number(n.attrs.width)),widths);
        // Strip only unsupported inline-block on the two original left anchors;
        // their physical TABLE/TD positions and content remain independently valid.
        const word=plan.html.replace(/display:inline-block;/g,'');
        const scope=contract(word,plan);
        // Word's pre-existing finite legacy proof still owns anchor formatting;
        // the physical row, rather than that formatting, proves separation.
        assert.equal(wrapperShape(word,plan,'rt-v17-action-pair').cells.length,3);
        assert.ok(scope.signature);
    }
});

test('new wrapper proof rejects foreign wrappers, altered spacer/slot geometry, changed WhatsApp and injected images', () => {
    const source=configured.source.standalone,plan=prepareNativeLayoutRestore(source,canonical(source,'standalone'),'87'.repeat(16),host('M'));
    const pair=wrapperShape(plan.html,plan,'rt-v17-action-pair'),phone=wrapperShape(plan.html,plan,'rt-v17-phone-action');
    const mutateNode=(node,from,to)=>plan.html.slice(0,node.start)+plan.html.slice(node.start,node.end).replace(from,to)+plan.html.slice(node.end);
    const changes=[
        plan.html.replace('class="rt-v17-action-pair"','class="foreign-wrapper"'),
        plan.html.replace('class="rt-v17-phone-action"','class="foreign-wrapper"'),
        mutateNode(pair.cells[1].node,'width="8"','width="9"'),
        mutateNode(phone.cells[0].node,'width="2"','width="3"'),
        mutateNode(phone.cells[2].node,'width:6px','width:7px'),
        plan.html.replace('https://wa.me/491601881848','https://wa.me/491601881849'),
        plan.html.replace('margin-left:0px;','margin-left:8px;'),
        plan.html.slice(0,pair.cells[1].node.openEnd)+'Injected'+plan.html.slice(pair.cells[1].node.openEnd),
        plan.html.slice(0,pair.cells[0].node.close)+'<img src="https://foreign.invalid/x.png" width="24" height="24">'+plan.html.slice(pair.cells[0].node.close),
        plan.html.replace('width:75px!important','width:62px!important'),
        plan.html.replace('width:108px!important;white-space','width:121px!important;white-space'),
    ];
    for(const changed of changes){assert.notEqual(changed,plan.html);assert.throws(()=>verifyFluidLayoutMaterial(changed,contract(changed,plan)));}
});

test('known Office text hosts retain both physical action groups without admitting foreign host formatting or ownership', () => {
    const source=configured.source.standalone,plan=prepareNativeLayoutRestore(source,canonical(source,'standalone'),'89'.repeat(16),host('B'));
    const edits=['rt-v17-action-pair','rt-v17-phone-action'].map((name,index)=>{
        const wrapper=wrapperShape(plan.html,plan,name),style='direction:ltr;line-height:19px;'+(index===0?'white-space:nowrap;':'')+'font-family:&quot;Trebuchet MS&quot;,Arial,sans-serif;font-size:13px;color:#ffffff;';
        return {start:wrapper.node.start,end:wrapper.node.end,value:'<div class="elementToProof" style="'+style+'">'+plan.html.slice(wrapper.node.start,wrapper.node.end)+'</div>'};
    });
    let received=plan.html;for(const edit of edits.sort((a,b)=>b.start-a.start))received=received.slice(0,edit.start)+edit.value+received.slice(edit.end);
    assert.equal(verifyFluidLayoutMaterial(received,contract(received,plan)).officeActionsWire,true);
    const hostOpening=edits.find(edit=>edit.value.includes('rt-v17-action-pair')).value.split('>')[0]+'>';
    for(const [before,after] of [['class="elementToProof"','class="foreign"'],['class="elementToProof"','class="elementToProof" id="foreign"'],
        ['direction:ltr;line-height:19px;','direction:ltr;line-height:20px;'],['font-size:13px;color:#ffffff;','font-size:13px;color:#000000;'],
        ['white-space:nowrap;','white-space:normal;']]) {
        const changed=received.replace(hostOpening,hostOpening.replace(before,after));assert.notEqual(changed,received);assert.throws(()=>verifyFluidLayoutMaterial(changed,contract(changed,plan)),before);
    }
    const partial=received.replace(edits.find(edit=>edit.value.includes('rt-v17-phone-action')).value,
        '<div class="elementToProof" style="direction:ltr;line-height:19px;font-family:&quot;Trebuchet MS&quot;,Arial,sans-serif;font-size:13px;color:#ffffff;"></div>');
    assert.throws(()=>verifyFluidLayoutMaterial(partial,contract(partial,plan)));
});

test('historical R782 physical-free action geometry stays readable but cannot satisfy the new Source plan or currentness', () => {
    const source=configured.source.standalone,plan=prepareNativeLayoutRestore(source,canonical(source,'standalone'),'88'.repeat(16),host('M'));
    const c=contract(plan.html,plan),nodes=c.elements,edits=[];
    for(const name of ['rt-v17-action-pair','rt-v17-phone-action']){
        const wrapper=wrapperShape(plan.html,plan,name);
        const anchors=nodes.filter((n,index)=>n.tag==='a'&&(()=>{for(let p=index;Number.isInteger(p);p=nodes[p]?.parent)if(p===wrapper.index)return true;return false;})());
        edits.push({start:wrapper.node.start,end:wrapper.node.end,value:anchors.map(n=>plan.html.slice(n.start,n.end).replace('margin-left:0px;','margin-left:14px;')).join('')});
    }
    const action=nodes.findIndex(n=>(n.attrs.class||'').includes('rt-v17-dispatch-actions'));
    const body=nodes.findIndex(n=>n.parent===action),row=nodes.findIndex(n=>n.parent===body),cells=nodes.filter(n=>n.parent===row);
    for(const [index,width] of [[0,62],[2,121]]){
        const n=cells[index];let value=plan.html.slice(n.start,n.openEnd).replace(/\bwidth="(?:75|108)"/, 'width="'+width+'"')
            .replace(/width:(?:75|108)px!important/,'width:'+width+'px!important').replace('padding-left:0!important;text-align:left!important;','');
        edits.push({start:n.start,end:n.openEnd,value});
    }
    let old=plan.html;for(const edit of edits.sort((a,b)=>b.start-a.start))old=old.slice(0,edit.start)+edit.value+old.slice(edit.end);
    const material=verifyFluidLayoutMaterial(old,contract(old,plan));
    assert.equal(material.officeActionsWire,false);assert.equal(material.defaultDispatchPhoneImage,true);assert.equal(plan.officeActionsWire,true);
    assert.equal(signatureHostProfileIsCurrent(old,old,plan.version,host('M')),false);
    assert.equal(standaloneSignatureLayoutIsCurrent(old,old,plan.version,host('M')),false);
});

test('nondefault dispatch numbers remain real text with a bounded nowrap geometry', () => {
    const source = fixture.source.standalone, plan = prepareNativeLayoutRestore(source, canonical(source, 'standalone'), '84'.repeat(16), host('B'));
    assert.equal(plan.defaultDispatchPhoneImage, false); assert.doesNotMatch(plan.html, /ef89fd23c6/); assert.match(plan.html, /\+491705550202<\/span>/);
    assert.equal(verifyFluidLayoutMaterial(plan.html, contract(plan.html, plan)).defaultDispatchPhoneImage, false);
});

test('reloaded currentness never labels old action geometry or a lost default phone image as current', () => {
    const source = configured.source.standalone;
    const plan = prepareNativeLayoutRestore(source, canonical(source, 'standalone'), '85'.repeat(16), host('M'));
    const sourceAction = /<table\b[^>]*rt-v17-dispatch-actions[^>]*>[\s\S]*?<\/table>/.exec(source)[0];
    const action = /<table\b[^>]*rt-v17-dispatch-actions[^>]*>[\s\S]*?<\/table>/.exec(plan.html)[0];
    assert.equal(signatureHostProfileIsCurrent(plan.html, plan.html, plan.version, host('M')), true);
    assert.equal(standaloneSignatureLayoutIsCurrent(plan.html, plan.html, plan.version, host('M')), true);
    for (const changed of [plan.html.replace(action, sourceAction), plan.html.replace(/<img\b[^>]*ef89fd23c6\.png[^>]*>/, '+49 160 1881839')]) {
        assert.equal(signatureHostProfileIsCurrent(changed, changed, plan.version, host('M')), false);
        assert.equal(standaloneSignatureLayoutIsCurrent(changed, changed, plan.version, host('M')), false);
    }
});

for (const [kind, source] of Object.entries(configured.mobile)) test('actual configured physical mobile ' + kind + ' keeps its flat auto table and uses only the bound default white phone PNG', () => {
    const wire = mobileV17OfficeWire(source);
    assert.match(wire, /ef89fd23c6\.png/); assert.match(wire, /width="94" height="17"/); assert.match(wire, /width:94px;height:auto/);
    assert.doesNotMatch(wire, /width:215px!important/); assert.match(wire, /table-layout:auto!important/);
    assert.doesNotMatch(mobileV17OfficeWire(kind === 'standalone' ? mobile.template.signature.html : mobile.template.mobileComposeHtml), /ef89fd23c6/);
});
