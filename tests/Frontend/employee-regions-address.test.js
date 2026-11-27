import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { registerPlaceLookup } from '../../resources/js/place-lookup.js';

const blade = readFileSync(new URL('../../resources/views/livewire/employees/profile/partials/employee-regions-address.blade.php', import.meta.url), 'utf8');
const source = blade.match(/x-data="([\s\S]*?)" x-on:click.outside/)[1];
function fixture() {
    const timers = new Map();
    let id = 0;
    const state = vm.runInNewContext(`(${source})`, {
        setTimeout: fn => { timers.set(++id, fn); return id; }, clearTimeout: timer => timers.delete(timer),
    });
    const writes = [], watches = {};
    state.$wire = { preferences: { base_location: 'Hamburg' }, set: (...args) => writes.push(args) };
    state.$watch = (key, callback) => watches[key] = callback;
    state.$nextTick = callback => callback();
    state.$refs = { suggestions: { querySelector: () => ({ scrollIntoView() {} }) } };
    state.init();
    return { state, timers, writes, watches };
}
const deferred = () => { let resolve; const promise = new Promise(done => resolve = done); return { promise, resolve }; };

test('typing updates only deferred origin text and replaces the pending debounce', () => {
    const { state, timers, writes } = fixture();
    state.query = 'Be'; state.input(); assert.equal(timers.size, 0);
    state.query = 'Ber'; state.input(); state.query = 'Berlin'; state.input();
    assert.equal(timers.size, 1);
    assert.deepEqual(writes.at(-1), ['preferences.base_location', 'Berlin', false]);
    state.destroy(); assert.equal(timers.size, 0);
});

test('out-of-order suggestions cannot replace the newest query', async () => {
    const { state } = fixture(); const older = deferred(), newer = deferred();
    state.$wire.searchAddresses = query => query === 'Berlin' ? older.promise : newer.promise;
    state.query = 'Berlin'; const first = state.search('Berlin', state.sequence);
    state.query = 'Hamburg'; state.cancel(); const second = state.search('Hamburg', state.sequence);
    newer.resolve({ status: 'known', results: [{ label: 'Hamburg', token: 'new' }] }); await second;
    older.resolve({ status: 'known', results: [{ label: 'Berlin', token: 'old' }] }); await first;
    assert.equal(state.results[0].label, 'Hamburg'); assert.equal(state.loading, false);
});

test('clearing and discarding invalidate in-flight results', async () => {
    const { state, watches } = fixture(); const pending = deferred(); state.$wire.searchAddresses = () => pending.promise;
    state.query = 'Berlin'; const request = state.search('Berlin', state.sequence);
    state.$wire.preferences.base_location = ''; watches['$wire.originVersion']();
    pending.resolve({ status: 'known', results: [{ label: 'Berlin', token: 'old' }] }); await request;
    assert.equal(state.query, ''); assert.equal(state.results.length, 0); assert.equal(state.open, false);
});

test('switching modes discards late searches without erasing the drafted origin', async () => {
    const { state, watches } = fixture(); const pending = deferred(); state.$wire.searchAddresses = () => pending.promise;
    state.query = 'Berlin'; const request = state.search('Berlin', state.sequence);
    watches.mode('states');
    pending.resolve({ status: 'known', results: [{ label: 'Berlin', token: 'old' }] }); await request;
    assert.equal(state.query, 'Berlin'); assert.equal(state.results.length, 0); assert.equal(state.open, false);
});

test('selection submits only the sealed token and suppresses duplicate clicks', async () => {
    const { state } = fixture(); const pending = deferred(); const calls = [];
    state.results = [{ label: 'Berlin', token: 'sealed' }];
    state.$wire.selectAddress = token => { calls.push(token); return pending.promise; };
    const selecting = state.choose(0); await state.choose(0);
    assert.deepEqual(calls, ['sealed']); assert.equal(state.open, false); assert.equal(state.selecting, true);
    pending.resolve(); await selecting; assert.equal(state.selecting, false);
});

test('provider failures and empty lists show actionable status without a fake origin', async () => {
    const { state, writes } = fixture(); state.query = 'Berlin';
    state.$wire.searchAddresses = async () => ({ status: 'unknown', reason: 'budget_exhausted', results: [] });
    await state.search('Berlin', state.sequence);
    assert.match(state.message, /Verbrauchsgrenze/); assert.equal(writes.length, 0);
    state.$wire.searchAddresses = async () => ({ status: 'known', results: [] }); await state.search('Berlin', state.sequence);
    assert.match(state.message, /Keine Adresse/);
});

test('keyboard selection wraps while coordinates retain honest precision labels', () => {
    const { state } = fixture(); state.results = [{}, {}]; state.move(1); assert.equal(state.active, 0);
    state.move(1); assert.equal(state.active, 1); state.move(1); assert.equal(state.active, 0);
    state.move(-1); assert.equal(state.active, 1);
    assert.match(state.precisionLabel('interpolated'), /angenähert/);
    assert.match(state.precisionLabel('locality'), /ungefähr/);
});

function placeField(config) {
    const registry = {};
    registerPlaceLookup({ data(name, factory) { registry[name] = factory; } });
    const state = registry.rtPlaceField(config);
    const values = {};
    state.$wire = { $set(key, value) { values[key] = value; }, $get(key) { return values[key]; } };
    return { state, values };
}

test('a chosen customer site fills structured fields and always requires fresh confirmation', async () => {
    const fields = {street:'form.street', postal:'form.postal_code',city:'form.city',country:'form.country',
        latitude:'form.latitude',longitude:'form.longitude',confirmed:'form.coordinates_confirmed'};
    const { state, values } = placeField({ fields, model: 'form.street' });
    await state.select({ name:'Werk', street:'Beispielweg 9', postal:'1010', city:'Wien', country:'AT', latitude:48.2, longitude:16.3 });
    assert.deepEqual(values, {'form.street':'Beispielweg 9','form.postal_code':'1010','form.city':'Wien',
        'form.country':'AT','form.latitude':48.2,'form.longitude':16.3,'form.coordinates_confirmed':false});
    state.clearSite();
    assert.equal(values['form.latitude'],'');
    assert.equal(values['form.longitude'],'');
});

test('locality selection stores the city and leaves other fields alone', async () => {
    const { state, values } = placeField({fields:{}, place:true, locality:true, model:'preferences.no_go_areas.0.location'});
    await state.select({city:'Hamburg',label:'Beispielstraße 7, Hamburg'});
    assert.deepEqual(values, {'preferences.no_go_areas.0.location':'Hamburg'});
});

test('portal corrections target the proposal only and a new result clears absent fields', async () => {
    const prefix = 'form.proposed_fields.';
    const {state, values} = placeField({fields:{street:prefix+'street', postal:prefix+'postal_code',city:prefix+'city',country:prefix+'country'}});
    await state.select({street:'Testweg',city:'Basel',country:'CH'});
    assert.equal(values[prefix+'postal_code'],'');
    assert.equal(values[prefix+'city'],'Basel');
    assert.equal(Object.keys(values).length,4);
});
