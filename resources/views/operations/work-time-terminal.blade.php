<x-guest-layout>
    <section class="mx-auto max-w-xl px-4 py-12" x-data="{
        userId:'',pin:'',terminalId:'',token:null,active:null,choices:[],choice:'internal',action:'start',busy:false,message:'',locationRequired:false,
        async request(url,body,bearer=null){
            const r=await fetch(url,{method:'POST',credentials:'omit',headers:{'Content-Type':'application/json','Accept':'application/json',...(bearer?{'Authorization':'Bearer '+bearer}:{})},body:JSON.stringify(body)});
            const data=await r.json().catch(()=>({}));
            if(!r.ok)throw new Error(r.status===404?'Terminal nicht freigegeben.':(data.message||'Erfassung konnte nicht bestätigt werden.'));
            return data;
        },
        reset(){this.userId='';this.pin='';this.token=null;this.active=null;this.choices=[];this.choice='internal';this.action='start';},
        async identify(){
            if(this.busy)return;this.busy=true;this.message='';
            try{const r=await this.request(@js(route('api.operations.terminal.authenticate')),{user_id:Number(this.userId),pin:this.pin,terminal_id:this.terminalId});this.pin='';this.token=r.token;this.active=r.active;this.choices=r.choices||[];this.locationRequired=!!r.location_required;this.action=this.active?(this.active.status==='running'?'pause':(this.active.status==='paused'?'resume':'submit')):'start';}
            catch(e){this.reset();this.message=e.message;}finally{this.busy=false;}
        },
        async capture(){
            if(this.busy||!this.token)return;this.busy=true;this.message='';
            try{
                let location={};
                if(this.locationRequired){const p=await new Promise((resolve,reject)=>{if(!navigator.geolocation)return reject(new Error('Standort nicht verfügbar.'));navigator.geolocation.getCurrentPosition(resolve,()=>reject(new Error('Standortfreigabe erforderlich.')),{enableHighAccuracy:true,timeout:10000,maximumAge:0});});location={latitude:p.coords.latitude,longitude:p.coords.longitude,accuracy:p.coords.accuracy};}
                const event={event_key:crypto.randomUUID(),action:this.action,revision:this.active?.revision||0};
                if(this.action==='start'){const duty=this.choices.find(c=>String(c.id)===this.choice);if(duty){event.assignment_id=duty.id;event.plan_revision=duty.plan_revision;event.work_context='shift';}else{event.work_context='internal';event.title='Interne Arbeit';}}
                const r=await this.request(@js(route('api.operations.terminal.capture')),{terminal_id:this.terminalId,event,location},this.token);
                this.reset();this.message=r.status==='accepted'?'Erfassung bestätigt.':(r.reason||'Erfassung zur Prüfung erhalten.');
            }catch(e){this.reset();this.message=e.message;}finally{this.busy=false;}
        }
    }">
        <div class="ops-panel ops-stack">
            <header class="ops-toolbar"><h1 class="text-xl font-semibold">Zeiterfassung</h1><span class="ops-kicker">RailTime</span></header>
            <p x-show="message" x-text="message" role="status" class="ops-muted"></p>
            <form x-show="!token" x-on:submit.prevent="identify" class="ops-form">
                <div class="ops-full space-y-1.5"><x-ui.forms.label for="terminal-device" value="Terminal-ID" /><x-ui.forms.input id="terminal-device" x-model="terminalId" required autocomplete="off" /></div>
                <div class="space-y-1.5"><x-ui.forms.label for="terminal-employee" value="Mitarbeiter-ID" /><x-ui.forms.number-input id="terminal-employee" min="1" x-model="userId" required autocomplete="off" :stepper="false" /></div>
                <div class="space-y-1.5"><x-ui.forms.label for="terminal-pin" value="PIN" /><x-ui.forms.input id="terminal-pin" type="password" inputmode="numeric" pattern="[0-9]{6,10}" x-model="pin" required autocomplete="off" /></div>
                <div class="ops-full"><x-ui.buttons.button-basic type="submit" mode="primary" x-bind:disabled="busy">Freigeben</x-ui.buttons.button-basic></div>
            </form>
            <form x-cloak x-show="token" x-on:submit.prevent="capture" class="ops-form">
                <div class="ops-full space-y-1.5" x-show="!active"><x-ui.forms.label for="terminal-context" value="Arbeitskontext" /><x-ui.dropdown.anchor-dropdown align="left" width="full" :match-trigger-width="true"><x-slot:trigger><x-ui.buttons.button-basic id="terminal-context" type="button" x-text="choices.find(c=>String(c.id)===choice)?.title||'Interne Arbeit'">Arbeitskontext</x-ui.buttons.button-basic></x-slot:trigger><x-slot:content><div class="ops-stack"><x-ui.buttons.button-basic type="button" x-on:click="choice='internal'; close()">Interne Arbeit</x-ui.buttons.button-basic><template x-for="duty in choices" :key="duty.id"><x-ui.buttons.button-basic type="button" x-on:click="choice=String(duty.id); close()" x-text="duty.title">Dienst</x-ui.buttons.button-basic></template></div></x-slot:content></x-ui.dropdown.anchor-dropdown></div>
                <fieldset class="ops-full flex flex-wrap gap-2" aria-label="Uhraktion">
                    <x-ui.buttons.button-basic type="button" x-show="!active" x-on:click="action='start'" x-bind:aria-pressed="action==='start'">Start</x-ui.buttons.button-basic>
                    <x-ui.buttons.button-basic type="button" x-show="active?.status==='running'" x-on:click="action='pause'" x-bind:aria-pressed="action==='pause'">Pause</x-ui.buttons.button-basic>
                    <x-ui.buttons.button-basic type="button" x-show="active?.status==='paused'" x-on:click="action='resume'" x-bind:aria-pressed="action==='resume'">Fortsetzen</x-ui.buttons.button-basic>
                    <x-ui.buttons.button-basic type="button" x-show="active &amp;&amp; ['running','paused'].includes(active.status)" x-on:click="action='stop'" x-bind:aria-pressed="action==='stop'">Ende</x-ui.buttons.button-basic>
                    <x-ui.buttons.button-basic type="button" x-show="active?.status==='completed'" x-on:click="action='submit'" x-bind:aria-pressed="action==='submit'">Einreichen</x-ui.buttons.button-basic>
                </fieldset>
                <div class="ops-full ops-actions"><x-ui.buttons.button-basic type="submit" mode="primary" x-bind:disabled="busy">Erfassen</x-ui.buttons.button-basic><x-ui.buttons.button-basic type="button" x-on:click="reset">Abbrechen</x-ui.buttons.button-basic></div>
            </form>
        </div>
    </section>
</x-guest-layout>
