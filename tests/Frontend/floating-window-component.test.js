import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import vm from 'node:vm';
import {parseHTML} from 'linkedom';

const blade = await readFile(new URL('../../resources/views/components/ui/floating/window.blade.php', import.meta.url),'utf8');
const code = blade.match(/<script data-rt-floating-window-controller>([\s\S]*?)<\/script>/)[1];
const launcher = await readFile(new URL('../../resources/views/components/ui/floating/launcher.blade.php', import.meta.url),'utf8');
const launcherGroup = await readFile(new URL('../../resources/views/components/ui/floating/launcher-group.blade.php', import.meta.url),'utf8');
const launcherCode = launcherGroup.match(/<script data-rt-floating-launcher-controller>([\s\S]*?)<\/script>/)[1];
const assistant = await readFile(new URL('../../resources/views/livewire/operations/ai-assist.blade.php', import.meta.url),'utf8');

function fixture({width=1223, reduced=false, animate=true}={}) {
    const {document, window:dom} = parseHTML('<html><body><button id="launcher" data-rt-window-launcher-for="window">Launch</button><section id="window" role="dialog"><header><span id="blank"></span><button>Tab</button><div role="dialog" id="settings"></div></header><div id="session"></div></section></body></html>');
    const panel=document.querySelector('section'), launcher=document.querySelector('#launcher'), ticks=[], nextTicks=[], watchers=[], animations=[], timers=new Map();
    if (typeof launcher.style.getPropertyPriority !== 'function') launcher.style.getPropertyPriority=()=> '';
    let id=0, disposed=0, captured=null, rendered=null;
    const state={open:false,mode:'mini',corner:'bottom-right'};
    const win={innerWidth:width,innerHeight:1252,matchMedia:()=>({matches:reduced}),addEventListener(){},removeEventListener(){}};
    panel.getBoundingClientRect=()=>{
        if(rendered) return rendered.rect;
        const size=state.mode==='mini'?[442,510]:[width-32,1150];
        const x=Number.parseFloat(panel.style.left)||(state.mode==='fullscreen'?16:state.corner.endsWith('left')?8:width-size[0]-8);
        const y=Number.parseFloat(panel.style.top)||(state.mode==='fullscreen'?86:state.corner.startsWith('top')?78:1252-size[1]-8);
        return {left:x,top:y,width:size[0],height:size[1]};
    };
    launcher.getBoundingClientRect=()=>launcher.style.display==='none'?{left:0,top:0,width:0,height:0}:launcher.testRect||({left:width-64,top:1180,width:56,height:56});
    panel.setPointerCapture=value=>captured=value;
    panel.hasPointerCapture=value=>captured===value;
    panel.releasePointerCapture=()=>captured=null;
    if(animate) panel.animate=(frames,options)=>{
        const result={frames,options,onfinish:null,cancelled:0,cancel(){this.cancelled++;rendered=null;}};
        animations.push(result); return result;
    };
    const context=vm.createContext({window:win,document,queueMicrotask:fn=>ticks.push(fn),
        getComputedStyle:element=>({getPropertyValue:()=>70,display:element.style.display||'block',visibility:'visible',transform:'none',opacity:String(rendered?.opacity??1)}),
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
    return {data,state,panel,launcher,document,win,animations,flush,
        open(value){state.open=value;flush()},
        finish(){animations.at(-1)?.onfinish?.();flush()},
        timers, render: (rect,opacity=1)=>rendered=rect?{rect,opacity}:null,
        disposeCount:()=>disposed,capture:()=>captured};
}

test('opening grows from its launcher into the target window geometry',()=>{
    const f=fixture();f.open(true);
    const a=f.animations.at(-1);
    assert.equal(a.options.duration,300);
    assert.equal(a.frames[0].left,'1159px');assert.equal(a.frames[0].top,'1180px');
    assert.equal(a.frames[0].width,'56px');assert.equal(a.frames[0].height,'56px');
    assert.equal(a.frames[1].left,'773px');assert.equal(a.frames[1].width,'442px');
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
    assert.equal(a.frames[0].width,'442px');assert.equal(a.frames[1].width,'1191px');
    assert.equal(a.frames[0].transform,'none');
    f.finish();f.data.resizeWindow();f.flush();f.finish();
    assert.equal(f.state.mode,'mini');assert.equal(f.state.corner,'bottom-right');
});

test('resize starts before a timer or paint can expose the target box',()=>{
    const f=fixture();f.open(true);f.finish();
    const count=f.animations.length;
    f.data.resizeWindow();f.flush({beforePaint:true});
    assert.equal(f.animations.length,count+1, 'first resize frame must exist before Alpine nextTick');
    assert.equal(f.animations.at(-1).frames[0].left,'773px');
    assert.equal(f.animations.at(-1).frames[1].left,'16px');
});

test('opening and choosing fullscreen in the same update starts only one reveal',()=>{
    const f=fixture();f.state.mode='fullscreen';f.state.open=true;f.flush({beforePaint:true});
    assert.equal(f.animations.length,1);
    assert.equal(f.data.windowMotion,'opening');
    assert.equal(f.animations[0].frames[0].width,'56px');
    assert.equal(f.animations[0].frames[1].width,'1191px');
    assert.match(blade,/:hidden="!windowVisible"/);
    assert.doesNotMatch(blade,/x-show="windowVisible"/, 'Alpine x-show delays visibility until another frame');
});

test('AI-Assist launcher opens through its owner and the window controls visibility',()=>{
    const launcherMarkup = assistant.match(/<x-ui\.floating\.launcher\b[\s\S]*?<\/x-ui\.floating\.launcher>/)?.[0] || '';
    const windowMarkup = assistant.match(/<x-ui\.floating\.window\b[\s\S]*?layout-action="syncLayout\(\);">/)?.[0] || '';
    assert.match(launcherMarkup,/x-on:click\.stop="\$dispatch\('railtime-assistant-open'\)"/);
    assert.doesNotMatch(launcherMarkup,/x-on:click\.stop="toggle\(\)"/);
    assert.match(launcherMarkup,/data-rt-window-launcher-for="\{\{ \$panelId \}\}"/);
    assert.match(assistant,/<x-ui\.dropdown\.anchor-dropdown[\s\S]*?x-on:railtime-assistant-open\.window="close\(\)"/);
    assert.match(windowMarkup,/<x-ui\.floating\.window\b/);
    assert.doesNotMatch(windowMarkup,/style=["']display:\s*none/i);
});

test('reversing a resize continues from its rendered partial box',()=>{
    const f=fixture();f.open(true);f.finish();f.data.resizeWindow();f.flush();
    const old=f.animations.at(-1), stale=old.onfinish;
    f.render({left:400,top:350,width:810,height:890},.8);
    f.data.resizeWindow();f.render(null);f.flush({beforePaint:true});
    const a=f.animations.at(-1);
    assert.equal(a.frames[0].left,'400px');assert.equal(a.frames[0].width,'810px');
    assert.equal(a.frames[0].opacity,.8);assert.equal(a.frames[1].width,'442px');
    stale();assert.equal(f.data.windowMotion,'resizing');f.finish();assert.equal(f.state.mode,'mini');
});

test('closing during resize and reopening keeps geometry and opacity continuous',()=>{
    const f=fixture();f.open(true);f.finish();f.data.resizeWindow();f.flush();
    f.render({left:400,top:350,width:810,height:890},.8);f.open(false);
    const closing=f.animations.at(-1);
    assert.equal(closing.frames[0].width,'810px');assert.equal(closing.frames[1].width,'56px');
    assert.equal(closing.frames[0].opacity,.8);
    f.render({left:400,top:354,width:810,height:890},.3);f.open(true);
    const opening=f.animations.at(-1);
    assert.equal(opening.frames[0].top,'354px');assert.equal(opening.frames[0].opacity,.3);
    assert.equal(opening.frames[1].width,'1191px');
    f.render(null);f.finish();assert.equal(f.data.windowVisible,true);
});

test('closing from fullscreen shrinks back to the launcher button',()=>{
    const f=fixture();f.open(true);f.finish();f.data.resizeWindow();f.flush();f.finish();
    assert.equal(f.state.mode,'fullscreen');
    f.open(false);
    const closing=f.animations.at(-1);
    assert.equal(closing.frames[0].width,'1191px');
    assert.equal(closing.frames[1].left,'1159px');
    assert.equal(closing.frames[1].width,'56px');
    assert.equal(closing.frames[1].height,'56px');
    f.finish();
    assert.equal(f.data.windowVisible,false);
});

test('closing from another docked corner returns to the repositioned launcher',()=>{
    const f=fixture();f.state.corner='top-left';f.open(true);f.finish();
    f.launcher.testRect={left:8,top:1180,width:56,height:56};
    f.open(false);
    const closing=f.animations.at(-1);
    assert.equal(closing.frames[1].left,'8px');
    assert.equal(closing.frames[1].top,'1180px');
    assert.equal(closing.frames[1].width,'56px');
    f.finish();
});

test('hidden launcher closing uses its last measured position',()=>{
    const f=fixture();f.open(true);f.finish();
    f.launcher.testRect={left:8,top:1180,width:56,height:56};
    f.launcher.style.setProperty('display','none');
    f.open(false);
    const closing=f.animations.at(-1);
    assert.equal(closing.frames[1].left,'8px');
    assert.equal(closing.frames[1].top,'1180px');
    assert.equal(closing.frames[1].width,'56px');
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

test('all three feature windows retain their owners while launcher items share a single teleport target outside main content',async()=>{
    for(const name of ['communication','operations/ai-assist','tools/chatbot']){
        const view=await readFile(new URL('../../resources/views/livewire/'+name+'.blade.php',import.meta.url),'utf8');
        assert.match(view,/<x-ui\.floating\.window/);
        assert.match(view,/<x-ui\.floating\.launcher-item\b/);
        assert.match(view,/resizeWindow\(\)/);
        assert.doesNotMatch(view,/drag-pointerdown=|x-transition:enter="rt-chatbot__panel-enter"/);
    }
    const launcherItem=await readFile(new URL('../../resources/views/components/ui/floating/launcher-item.blade.php',import.meta.url),'utf8');
    const master=await readFile(new URL('../../resources/views/layouts/master.blade.php',import.meta.url),'utf8');
    assert.match(launcherItem,/<template x-teleport="\[data-rt-floating-launcher-group\]">/);
    assert.equal((master.match(/<x-ui\.floating\.launcher-group\s/g)||[]).length,1);
    assert.ok(master.indexOf('</main>')<master.indexOf('<x-ui.floating.launcher-group'),
        'fullscreen main-content inert state must not hide the launcher teleport target');
    assert.doesNotMatch(launcher,/\bx-data\b|<script\b/,'launcher refs must retain their feature owner scope');
    assert.doesNotMatch(launcherItem,/\bx-data\b/,'the item component must not introduce another ref scope');
    assert.match(launcher,/\bx-rt-launcher\b/);
    assert.doesNotMatch(launcherGroup,/\bx-data\b/,'the shared geometry container never takes ownership of feature state');
    assert.match(blade,/<template x-teleport="body">/);
    assert.match(blade,/x-transition\.opacity\.duration\.100ms/);
    assert.match(blade,/\.before\('bind'\)/);
    assert.match(blade,/Alpine\.addScopeToNode\(panel, controls\)/, 'each window has isolated reactive state while retaining feature refs');
});

test('launcher component keeps both buttons in the same persisted left/right group',()=>{
    const f=launcherGroupFixture();const releaseChat=f.api.register(f.chat),releaseAssist=f.api.register(f.assist);f.flush();
    for(const [button,side,key] of [[f.chat,'left','ArrowLeft'],[f.assist,'right','ArrowRight']]){
        f.event(button,'keydown',{altKey:true,key});f.flush();
        assert.equal(f.document.documentElement.dataset.rtLauncherSide,side);
        assert.equal(f.chatItem.parentElement,f.group);assert.equal(f.assistItem.parentElement,f.group);
    }
    assert.equal(f.storage.get('railtime-floating-launcher-side'),'right');
    releaseChat();releaseAssist();
});

function resolveLauncherBottom(overrides={}) {
    const context=vm.createContext({window:{},document:{addEventListener(){}}});
    vm.runInContext(launcherCode,context);
    return context.window.rtResolveFloatingLauncherBottom({
        height:1000,viewportBottom:1000,preferredBottom:100,minTop:76,
        stackHeight:124,left:940,right:996,obstacles:[],...overrides,
    });
}

test('launcher group clears a list footer while preserving the preferred height when it is already clear',()=>{
    assert.equal(resolveLauncherBottom(),100);
    assert.equal(resolveLauncherBottom({obstacles:[{top:870,bottom:930,left:0,right:1000}]}),142);
    assert.equal(resolveLauncherBottom({obstacles:[{top:920,bottom:980,left:0,right:1000}]}),100,
        'a footer below the whole group must not add another offset');
    assert.equal(resolveLauncherBottom({obstacles:[{top:906,bottom:980,left:0,right:1000}]}),106,
        'a footer within the clearance gap must be avoided before it overlaps a button');
});

test('launcher footer avoidance ignores another column and rectangles outside the visible viewport',()=>{
    const obstacles=[
        {top:800,bottom:930,left:0,right:900},
        {top:1001,bottom:1060,left:0,right:1000},
        {top:-100,bottom:-20,left:0,right:1000},
    ];
    assert.equal(resolveLauncherBottom({obstacles}),100);
    assert.equal(resolveLauncherBottom({left:8,right:64,obstacles:[{top:870,bottom:930,left:100,right:1000}]}),100,
        'a left-docked group only avoids footers in its own column');
    assert.equal(resolveLauncherBottom({left:8,right:64,obstacles:[{top:870,bottom:930,left:0,right:1000}]}),142);
});

test('clearing a footer repeatedly raises the whole launcher group past newly encountered obstacles',()=>{
    const first={top:700,bottom:750,left:0,right:1000};
    const second={top:870,bottom:930,left:0,right:1000};
    assert.equal(resolveLauncherBottom({obstacles:[first,second]}),312);
    assert.equal(resolveLauncherBottom({obstacles:[second,first]}),312,
        'footer enumeration order must not affect the final position');
});

test('mobile launchers clear the bottom menu and stay inside the keyboard-reduced viewport',()=>{
    assert.equal(resolveLauncherBottom({
        height:844,viewportBottom:844,preferredBottom:16,minTop:76,stackHeight:108,
        left:326,right:382,obstacles:[{top:768,bottom:844,left:0,right:390}],
    }),88);
    assert.equal(resolveLauncherBottom({
        height:844,viewportBottom:544,preferredBottom:352,minTop:76,stackHeight:108,
        left:326,right:382,obstacles:[{top:500,bottom:544,left:0,right:390}],
    }),356,
        'visible coordinates must be converted back into a layout viewport bottom offset');
    assert.equal(resolveLauncherBottom({height:844,viewportBottom:544,preferredBottom:0,stackHeight:108}),312,
        'the keyboard-covered area is never a valid launcher position');
});

test('launcher placement clamps an oversized lift below the top bar even when avoidance has no free solution',()=>{
    assert.equal(resolveLauncherBottom({preferredBottom:2000}),800);
    assert.equal(resolveLauncherBottom({obstacles:[{top:0,bottom:1000,left:0,right:1000}]}),800);
    assert.equal(resolveLauncherBottom({preferredBottom:-30}),12);
});

function launcherGroupFixture() {
    const {document,window:dom}=parseHTML(`<html><body>
        <header data-rt-shell-topbar></header>
        <main id="main-content"><div data-table-pagination id="footer"></div></main>
        <nav data-area-menu id="dock"></nav>
        <div data-rt-floating-launcher-group data-rt-launcher-layout="stack">
            <div data-rt-floating-launcher-item="communication" class="rt-floating-launcher-item--communication"><button data-rt-floating-launcher class="rt-comm-launcher" id="chat"></button></div>
            <div data-rt-floating-launcher-item="assist" class="rt-ai-assist"><div class="rt-ai-assist__stage"><button data-rt-floating-launcher id="assist"></button></div></div>
        </div>
    </body></html>`);
    const chat=document.querySelector('#chat'),assist=document.querySelector('#assist'),stage=assist.parentElement;
    const group=document.querySelector('[data-rt-floating-launcher-group]'),chatItem=chat.parentElement,assistItem=stage.parentElement;
    const footer=document.querySelector('#footer'),dock=document.querySelector('#dock');
    const rafs=new Map(),mutations=[],resizes=[],storage=new Map();let frameId=0;
    const target=()=>{
        const listeners=new Map();
        return {
            addEventListener(name,fn){if(!listeners.has(name))listeners.set(name,new Set());listeners.get(name).add(fn);},
            removeEventListener(name,fn){listeners.get(name)?.delete(fn);},
            dispatchEvent(event){for(const fn of listeners.get(event.type)||[])fn(event);},
        };
    };
    const shape=(left,top,width,height)=>({left,top,width,height,right:left+width,bottom:top+height});
    footer.testRect=shape(0,1050,1000,50);dock.testRect=shape(0,920,1000,80);
    document.querySelector('header').testRect=shape(0,0,1000,70);
    const visibleItems=rail=>[...rail.querySelectorAll('[data-rt-floating-launcher-item]')]
        .filter(item=>item.testDisplay!=='none'&&item.style.display!=='none'&&item.querySelector('button')?.testDisplay!=='none')
        .sort((a,b)=>Number(a.dataset.rtFloatingLauncherItem==='communication')-Number(b.dataset.rtFloatingLauncherItem==='communication'));
    const railRect=rail=>{
        const items=visibleItems(rail),row=rail.dataset.rtLauncherLayout==='row';
        const width=items.length?(row?items.length*56+(items.length-1)*12:56):0;
        const height=items.length?(row?56:items.length*56+(items.length-1)*12):0;
        const x=Number.parseFloat(rail.style.getPropertyValue('--rt-floating-launcher-x'));
        const bottom=Number.parseFloat(rail.style.getPropertyValue('--rt-floating-launcher-bottom'))||0;
        const left=Number.isFinite(x)?x:document.documentElement.dataset.rtLauncherSide==='left'?12:win.innerWidth-width-12;
        return shape(left,win.innerHeight-bottom-height,width,height);
    };
    dom.HTMLElement.prototype.getBoundingClientRect=function(){
        if(this.testRect)return this.testRect;
        if(this.matches('[data-rt-floating-launcher-group]'))return railRect(this);
        const item=this.closest('[data-rt-floating-launcher-item]'),rail=item?.parentElement;
        if(item&&rail?.matches('[data-rt-floating-launcher-group]')){
            const items=visibleItems(rail),index=items.indexOf(item);
            if(index<0)return shape(0,0,0,0);
            const rect=railRect(rail),row=rail.dataset.rtLauncherLayout==='row';
            return shape(rect.left+(row?index*68:0),rect.top+(row?0:index*68),56,56);
        }
        return shape(0,0,1000,1000);
    };
    class MutationObserver {
        constructor(callback){this.callback=callback;mutations.push(this);}
        observe(){}
        disconnect(){this.disconnected=true;}
    }
    class ResizeObserver {
        constructor(callback){this.callback=callback;this.observed=new Set();resizes.push(this);}
        observe(node){this.observed.add(node);}
        unobserve(node){this.observed.delete(node);}
        disconnect(){this.observed.clear();this.disconnected=true;}
    }
    const win={...target(),innerWidth:1000,innerHeight:1000,
        visualViewport:{...target(),height:1000,offsetTop:0},MutationObserver,ResizeObserver,
        localStorage:{getItem:key=>storage.get(key),setItem:(key,value)=>storage.set(key,value)},
        getComputedStyle:node=>({display:node.testDisplay||'block',visibility:'visible',opacity:'1',
            overflowX:'visible',overflowY:'visible',position:node.testPosition||'static'}),
        requestAnimationFrame:callback=>{const id=++frameId;rafs.set(id,callback);return id;},
        cancelAnimationFrame:id=>rafs.delete(id),
    };
    const context=vm.createContext({window:win,document,CustomEvent:dom.CustomEvent,performance:{now:()=>1000}});
    vm.runInContext(launcherCode,context);
    const api=win.rtInitializeFloatingLauncherGroup(document,win);
    const flush=()=>{
        for(let guard=0;rafs.size;guard++){
            assert.ok(guard<10,'Launcher coordinator must settle after a layout update');
            const pending=[...rafs.values()];rafs.clear();pending.forEach(callback=>callback());
        }
    };
    return {document,win,api,group,chat,assist,stage,chatItem,assistItem,footer,dock,rafs,mutations,resizes,storage,shape,flush,
        mutate(){mutations.at(-1).callback([{type:'childList',target:document.body}]);flush();},
        scroll(){document.dispatchEvent(new dom.Event('scroll'));flush();},
        event(node,type,data={}){const event=new dom.Event(type,{bubbles:true,cancelable:true});Object.assign(event,data);node.dispatchEvent(event);return event;},
        bottom:node=>Number.parseFloat(node.style.getPropertyValue('--rt-floating-launcher-bottom')),
    };
}

test('shared coordinator reacts to footer changes, scrolling, dock visibility, and hidden launchers without accumulating offsets',()=>{
    const f=launcherGroupFixture();f.api.register(f.chat);f.api.register(f.assist);f.flush();
    assert.equal(f.bottom(f.group),136);
    assert.equal(f.chat.getBoundingClientRect().top-f.assist.getBoundingClientRect().bottom,12);
    for(const node of [f.chat,f.assist,f.stage,f.chatItem,f.assistItem]){
        assert.equal(node.style.getPropertyValue('--rt-floating-launcher-bottom'),undefined,'the shared container owns every launcher offset');
        assert.equal(node.style.getPropertyValue('--rt-floating-launcher-x'),undefined);
    }
    f.footer.testRect=f.shape(0,830,1000,50);f.mutate();
    assert.equal(f.bottom(f.group),182);
    f.scroll();assert.equal(f.bottom(f.group),182,'repeated measurement never adds a second footer lift');
    f.footer.testRect=f.shape(0,1050,1000,50);f.scroll();
    assert.equal(f.bottom(f.group),136);
    f.dock.testRect=f.shape(0,850,1000,150);f.mutate();
    assert.equal(f.bottom(f.group),136,'desktop navigation is not a bottom dock');
    f.dock.testPosition='fixed';f.mutate();
    assert.equal(f.bottom(f.group),162);
    f.dock.testDisplay='none';f.chat.testDisplay='none';f.mutate();
    assert.equal(f.bottom(f.group),136,'the remaining visible assistant occupies the bottom of the group');
    assert.equal(f.group.getBoundingClientRect().height,56);
    f.api.destroy();
});

test('a short viewport uses one compact launcher row above the footer and dock then restores the normal stack',()=>{
    const f=launcherGroupFixture();const releaseChat=f.api.register(f.chat),releaseAssist=f.api.register(f.assist);f.flush();
    f.win.innerWidth=390;f.win.innerHeight=300;f.win.visualViewport.height=300;
    f.footer.testRect=f.shape(0,190,390,30);
    f.dock.testPosition='fixed';f.dock.testRect=f.shape(0,244,390,56);
    f.win.dispatchEvent({type:'resize'});f.flush();
    assert.equal(f.group.dataset.rtLauncherLayout,'row');
    assert.equal(f.bottom(f.group),122);
    const chatRect=f.chat.getBoundingClientRect(),assistRect=f.assist.getBoundingClientRect(),railRect=f.group.getBoundingClientRect();
    assert.equal(chatRect.top,assistRect.top);
    assert.equal(chatRect.left-assistRect.right,12,'the compact row preserves the 12px gap between 56px buttons');
    assert.ok(railRect.left>=12&&railRect.right<=378,'both buttons stay inside the viewport sides');
    assert.ok(railRect.top>=82,'the compact row stays below the top bar');
    assert.equal(f.footer.testRect.top-railRect.bottom,12,'the row clears the footer above the bottom dock');

    f.win.innerWidth=1000;f.win.innerHeight=1000;f.win.visualViewport.height=1000;
    f.footer.testRect=f.shape(0,1050,1000,50);f.dock.testRect=f.shape(0,1050,1000,80);
    f.win.dispatchEvent({type:'resize'});f.flush();
    assert.equal(f.group.dataset.rtLauncherLayout,'stack');
    for(const node of [f.chat,f.assist,f.stage,f.chatItem,f.assistItem]){
        assert.equal(node.style.getPropertyValue('--rt-floating-launcher-x'),undefined);
        assert.equal(node.style.getPropertyValue('--rt-floating-launcher-bottom'),undefined);
    }
    assert.equal(f.bottom(f.group),136);
    assert.equal(f.chat.getBoundingClientRect().top-f.assist.getBoundingClientRect().bottom,12);
    releaseChat();releaseAssist();
});

test('last launcher cleanup disconnects observers and events and permits a fresh coordinator after navigation',()=>{
    const f=launcherGroupFixture();
    assert.equal(f.win.rtInitializeFloatingLauncherGroup(f.document,f.win),f.api,'registration reuses the document coordinator');
    const releaseChat=f.api.register(f.chat),releaseAssist=f.api.register(f.assist);f.flush();
    assert.ok(f.resizes[0].observed.has(f.footer));
    releaseChat();f.flush();assert.equal(f.resizes[0].disconnected,undefined);
    releaseAssist();
    assert.equal(f.resizes[0].disconnected,true);assert.equal(f.mutations[0].disconnected,true);
    assert.equal(f.group.style.getPropertyValue('--rt-floating-launcher-bottom'),undefined);
    assert.equal(f.group.style.getPropertyValue('--rt-floating-launcher-x'),undefined);
    assert.equal(f.document.documentElement._rtLauncherGroup,undefined);
    f.win.dispatchEvent({type:'resize'});f.win.visualViewport.dispatchEvent({type:'scroll'});f.scroll();
    assert.equal(f.rafs.size,0,'disposed listeners cannot restart background layout work');
    const next=f.win.rtInitializeFloatingLauncherGroup(f.document,f.win);
    assert.notEqual(next,f.api);const release=next.register(f.chat);f.flush();
    assert.equal(f.bottom(f.group),136);release();
    assert.equal(f.resizes[1].disconnected,true);
});

test('navigation remeasures a replacement shared rail while preserving both registered launcher wrappers',()=>{
    const f=launcherGroupFixture();const releaseChat=f.api.register(f.chat),releaseAssist=f.api.register(f.assist);f.flush();
    const next=f.document.createElement('div');next.setAttribute('data-rt-floating-launcher-group','');next.dataset.rtLauncherLayout='stack';
    next.append(f.chatItem,f.assistItem);f.group.replaceWith(next);
    f.event(f.document,'livewire:navigated');f.flush();
    assert.equal(f.bottom(next),136);assert.equal(f.chatItem.parentElement,next);assert.equal(f.assistItem.parentElement,next);
    assert.equal(f.chat.getBoundingClientRect().top-f.assist.getBoundingClientRect().bottom,12);
    assert.equal(f.resizes[0].observed.has(f.group),false);
    assert.equal(f.resizes[0].observed.has(next),true);
    releaseChat();releaseAssist();
    assert.equal(next.style.getPropertyValue('--rt-floating-launcher-bottom'),undefined);
});

test('the shared rail moves beside an open mini window and returns together when that window closes',()=>{
    const f=launcherGroupFixture();const releaseChat=f.api.register(f.chat),releaseAssist=f.api.register(f.assist);f.flush();
    const panel=f.document.createElement('section');panel.className='rt-floating-window';
    panel.dataset.floatingMode='mini';panel.dataset.floatingOpen='true';panel.testRect=f.shape(700,300,284,684);
    f.document.body.append(panel);f.mutate();
    assert.equal(f.bottom(f.group),136,'a free lateral position preserves the preferred height');
    assert.equal(f.group.getBoundingClientRect().right,panel.testRect.left-12);
    assert.equal(f.chatItem.parentElement,f.group);assert.equal(f.assistItem.parentElement,f.group);
    assert.equal(f.chat.getBoundingClientRect().top-f.assist.getBoundingClientRect().bottom,12);
    assert.ok(f.resizes[0].observed.has(panel));
    panel.dataset.floatingOpen='false';f.mutate();
    assert.equal(f.group.getBoundingClientRect().right,984);
    assert.equal(f.resizes[0].observed.has(panel),false);
    releaseChat();releaseAssist();
});
