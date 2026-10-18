import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';
import {parseHTML} from 'linkedom';

const blade = await readFile(new URL('../../resources/views/components/ui/floating/window.blade.php', import.meta.url),'utf8');
const code = blade.match(/<script data-rt-floating-window-controller>([\s\S]*?)<\/script>/)[1];
const launcher = await readFile(new URL('../../resources/views/components/ui/floating/launcher.blade.php', import.meta.url),'utf8');
const launcherCode = launcher.match(/<script data-rt-floating-launcher-controller>([\s\S]*?)<\/script>/)[1];

function fixture({width=1223, reduced=false, animate=true}={}) {
    const {document, window:dom} = parseHTML('<html><body><section role="dialog"><header><span id="blank"></span><button>Tab</button><div role="dialog" id="settings"></div></header><div id="session"></div></section></body></html>');
    const panel=document.querySelector('section'), ticks=[], nextTicks=[], watchers=[], animations=[], timers=new Map();
    let id=0, disposed=0, captured=null, rendered=null;
    const state={open:false,mode:'mini',corner:'bottom-right'};
    const win={innerWidth:width,innerHeight:1252,matchMedia:()=>({matches:reduced})};
    panel.getBoundingClientRect=()=>{
        if(rendered) return rendered.rect;
        const size=state.mode==='mini'?[520,600]:[width-32,1150];
        const x=Number.parseFloat(panel.style.left)||(state.mode==='fullscreen'?16:state.corner.endsWith('left')?8:width-size[0]-8);
        const y=Number.parseFloat(panel.style.top)||(state.mode==='fullscreen'?86:state.corner.startsWith('top')?78:1252-size[1]-8);
        return {left:x,top:y,width:size[0],height:size[1]};
    };
    panel.setPointerCapture=value=>captured=value;
    panel.hasPointerCapture=value=>captured===value;
    panel.releasePointerCapture=()=>captured=null;
    if(animate) panel.animate=(frames,options)=>{
        const result={frames,options,onfinish:null,cancelled:0,cancel(){this.cancelled++;rendered=null;}};
        animations.push(result); return result;
    };
    const context=vm.createContext({window:win,document,queueMicrotask:fn=>ticks.push(fn),
        getComputedStyle:()=>({getPropertyValue:()=>70,visibility:'visible',transform:'none',opacity:String(rendered?.opacity??1)}),
        setTimeout:fn=>{timers.set(++id,fn);return id;},clearTimeout:key=>timers.delete(key)});
    vm.runInContext(code,context);
    const data={destroy(){disposed++},$watch(fn,callback){watchers.push({fn,callback,last:fn()})},$nextTick:fn=>nextTicks.push(fn)};
    const config={opened:()=>state.open,mode:()=>state.mode,corner:()=>state.corner,enabled:()=>true,
        setMode:value=>state.mode=value,setCorner:value=>state.corner=value};
    Object.assign(data,context.window.rtFloatingWindow(config,panel,data));
    data.initWindow();
    const flush=({beforePaint=false}={})=>{
        for(let i=0;i<20;i++){
            let changed=false;
            for(const w of watchers){const now=w.fn();if(now!==w.last){w.last=now;w.callback(now);changed=true}}
            if(!ticks.length&&!changed){
                if(beforePaint||!nextTicks.length)return;
                nextTicks.splice(0).forEach(fn=>fn());
                continue;
            }
            ticks.splice(0).forEach(fn=>fn());
        }
        assert.fail('Unbounded window work');
    };
    flush();
    return {data,state,panel,document,win,animations,flush,
        open(value){state.open=value;flush()},
        finish(){animations.at(-1)?.onfinish?.();flush()},
        timers, render: (rect,opacity=1)=>rendered=rect?{rect,opacity}:null,
        disposeCount:()=>disposed,capture:()=>captured};
}

test('shared opening is compositor-only and never depends on the moving peer launcher',()=>{
    const f=fixture();f.open(true);
    const a=f.animations.at(-1);
    assert.equal(a.options.duration,260);
    assert.deepEqual(Object.keys(a.frames[0]).sort(),['opacity','transform']);
    assert.equal(a.frames[0].transform,'translateY(8px)');
    assert.equal(f.data.windowVisible,true);f.finish();assert.equal(f.data.windowMotion,'idle');
    assert.equal(a.cancelled,1);
});

test('close keeps the same session mounted and rapid reopen rejects stale completion',()=>{
    const f=fixture(),session=f.document.querySelector('#session');f.open(true);f.finish();
    f.open(false);const old=f.animations.at(-1),stale=old.onfinish;
    assert.equal(f.data.windowVisible,true);
    f.open(true);stale();assert.equal(f.data.windowVisible,true);f.finish();
    assert.equal(f.document.querySelector('#session'),session);
    f.open(false);f.finish();assert.equal(f.data.windowVisible,false);
});

test('resize uses real geometry and preserves the selected docking corner',()=>{
    const f=fixture();f.open(true);f.finish();f.data.resizeWindow();f.flush();
    assert.equal(f.state.mode,'fullscreen');
    const a=f.animations.at(-1);
    assert.equal(a.frames[0].width,'520px');assert.equal(a.frames[1].width,'1191px');
    assert.equal(a.frames[0].transform,'none');
    f.finish();f.data.resizeWindow();f.flush();f.finish();
    assert.equal(f.state.mode,'mini');assert.equal(f.state.corner,'bottom-right');
});

test('resize starts before a timer or paint can expose the target box',()=>{
    const f=fixture();f.open(true);f.finish();
    const count=f.animations.length;
    f.data.resizeWindow();f.flush({beforePaint:true});
    assert.equal(f.animations.length,count+1, 'first resize frame must exist before Alpine nextTick');
    assert.equal(f.animations.at(-1).frames[0].left,'695px');
    assert.equal(f.animations.at(-1).frames[1].left,'16px');
});

test('opening and choosing fullscreen in the same update starts only one reveal',()=>{
    const f=fixture();f.state.mode='fullscreen';f.state.open=true;f.flush({beforePaint:true});
    assert.equal(f.animations.length,1);
    assert.equal(f.data.windowMotion,'opening');
    assert.deepEqual(Object.keys(f.animations[0].frames[0]).sort(),['opacity','transform']);
    assert.match(blade,/:hidden="!windowVisible"/);
    assert.doesNotMatch(blade,/x-show="windowVisible"/, 'Alpine x-show delays visibility until another frame');
});

test('reversing a resize continues from its rendered partial box',()=>{
    const f=fixture();f.open(true);f.finish();f.data.resizeWindow();f.flush();
    const old=f.animations.at(-1), stale=old.onfinish;
    f.render({left:400,top:350,width:810,height:890},.8);
    f.data.resizeWindow();f.render(null);f.flush({beforePaint:true});
    const a=f.animations.at(-1);
    assert.equal(a.frames[0].left,'400px');assert.equal(a.frames[0].width,'810px');
    assert.equal(a.frames[0].opacity,.8);assert.equal(a.frames[1].width,'520px');
    stale();assert.equal(f.data.windowMotion,'resizing');f.finish();assert.equal(f.state.mode,'mini');
});

test('closing during resize and reopening keeps geometry and opacity continuous',()=>{
    const f=fixture();f.open(true);f.finish();f.data.resizeWindow();f.flush();
    f.render({left:400,top:350,width:810,height:890},.8);f.open(false);
    const closing=f.animations.at(-1);
    assert.equal(closing.frames[0].width,'810px');assert.equal(closing.frames[1].width,'810px');
    assert.equal(closing.frames[0].opacity,.8);
    f.render({left:400,top:354,width:810,height:890},.3);f.open(true);
    const opening=f.animations.at(-1);
    assert.equal(opening.frames[0].top,'354px');assert.equal(opening.frames[0].opacity,.3);
    assert.equal(opening.frames[1].width,'1191px');
    f.render(null);f.finish();assert.equal(f.data.windowVisible,true);
});

test('the outer dialog can drag while header buttons and nested dialogs cannot',()=>{
    const f=fixture();f.open(true);f.finish();
    const start={isPrimary:true,button:0,pointerId:7,clientX:900,clientY:660};
    for(const target of [f.panel.querySelector('button'),f.document.querySelector('#settings')]){
        f.data.windowDragStart({...start,target});f.data.windowDragMove({...start,clientX:100,clientY:100,preventDefault(){}});
        assert.equal(f.data.windowDragging,false);
    }
    f.data.windowDragStart({...start,target:f.document.querySelector('#blank')});
    f.data.windowDragMove({...start,clientX:100,clientY:100,preventDefault(){}});
    assert.equal(f.data.windowDragging,true);assert.equal(f.capture(),7);
    assert.equal(f.data.windowDockTarget,'top-left');
    f.data.windowDragEnd({pointerId:7});f.flush();f.finish();
    assert.equal(f.state.corner,'top-left');assert.equal(f.capture(),null);
});

test('keyboard docking reaches all four 8px edge positions',()=>{
    const f=fixture();f.open(true);f.finish();
    for(const [key,corner] of [['ArrowLeft','bottom-left'],['ArrowUp','top-left'],['ArrowRight','top-right'],['ArrowDown','bottom-right']]){
        f.data.windowDockKey({altKey:true,key,preventDefault(){}});f.flush();f.finish();assert.equal(f.state.corner,corner);
        const r=f.panel.getBoundingClientRect();assert.equal(corner.endsWith('left')?r.left:f.win.innerWidth-r.left-r.width,8);
    }
});

test('mobile cannot enter mini mode or start a header drag',()=>{
    const f=fixture({width:390});f.state.mode='fullscreen';f.open(true);f.finish();
    f.data.resizeWindow();assert.equal(f.state.mode,'fullscreen');
    assert.equal(f.data.canMoveWindow(),false);
});

test('reduced motion and CSS fallback both reach the correct final visibility',()=>{
    const reduced=fixture({reduced:true});reduced.open(true);assert.equal(reduced.animations.length,0);
    reduced.open(false);assert.equal(reduced.data.windowVisible,false);
    const fallback=fixture({animate:false});fallback.open(true);assert.equal(fallback.data.windowMotion,'opening');
    [...fallback.timers.values()].forEach(fn=>fn());assert.equal(fallback.data.windowMotion,'idle');
    fallback.open(false);[...fallback.timers.values()].forEach(fn=>fn());assert.equal(fallback.data.windowVisible,false);
});

test('destruction and resize cancel work without destroying feature state twice',()=>{
    const f=fixture();f.open(true);const a=f.animations.at(-1);
    f.data.resetWindow();f.flush();assert.ok(a.cancelled);assert.equal(f.data.windowVisible,true);
    f.open(false);const closing=f.animations.at(-1);f.data.destroyWindow();closing.onfinish();
    assert.equal(f.disposeCount(),0, 'window cleanup must not invoke the feature owner cleanup');
});

test('all three feature views consume the component and only the docking guide is teleported',async()=>{
    for(const name of ['communication','operations/ai-assist','tools/chatbot']){
        const view=await readFile(new URL('../../resources/views/livewire/'+name+'.blade.php',import.meta.url),'utf8');
        assert.match(view,/<x-ui\.floating\.window/);
        assert.match(view,/resizeWindow\(\)/);
        assert.doesNotMatch(view,/drag-pointerdown=|x-transition:enter="rt-chatbot__panel-enter"/);
    }
    assert.match(blade,/<template x-teleport="body">/);
    assert.match(blade,/x-transition\.opacity\.duration\.100ms/);
    assert.match(blade,/\.before\('bind'\)/);
    assert.match(blade,/Alpine\.addScopeToNode\(panel, controls\)/, 'each window has isolated reactive state while retaining feature refs');
});

test('launcher component keeps both buttons in the same persisted left/right group',()=>{
    const {document,window:dom}=parseHTML('<html><body><button data-rt-floating-launcher id="chat"></button><button data-rt-floating-launcher id="ai"></button></body></html>');
    const storage=new Map(),win={innerWidth:1223,localStorage:{getItem:k=>storage.get(k),setItem:(k,v)=>storage.set(k,v)},dispatchEvent(){}};
    const context=vm.createContext({window:win,document,queueMicrotask:fn=>ticks.push(fn),CustomEvent:dom.CustomEvent,performance:{now:()=>1000}});
    vm.runInContext(launcherCode,context);win.rtInitializeFloatingLauncherGroup(document,win);
    for(const id of ['chat','ai']){
        const event=new dom.Event('keydown',{bubbles:true,cancelable:true});event.altKey=true;event.key=id==='chat'?'ArrowLeft':'ArrowRight';
        document.getElementById(id).dispatchEvent(event);
        assert.equal(document.documentElement.dataset.rtLauncherSide,id==='chat'?'left':'right');
    }
    assert.equal(storage.get('railtime-floating-launcher-side'),'right');
});
