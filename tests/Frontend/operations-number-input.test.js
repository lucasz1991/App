import test from 'node:test';
import assert from 'node:assert/strict';
import { numberInput } from '../../resources/js/number-input.js';

function mount(config = {}, value = '') {
    const events = [];
    const field = {
        value,
        disabled: false,
        readOnly: false,
        selectionStart: value.length,
        selectionEnd: value.length,
        dispatchEvent(event) { events.push(event.type); },
        setRangeText(text, start, end) {
            this.value = this.value.slice(0, start) + text + this.value.slice(end);
            this.selectionStart = this.selectionEnd = start + text.length;
        },
    };
    const component = numberInput(config);
    component.$refs = { field };
    component.$el = field;

    return { component, field, events };
}

test('integer steps respect boundaries and notify model listeners', () => {
    const { component, field, events } = mount({ min: 1, max: 999, nullable: false }, '1');
    component.nudge(-1);
    assert.equal(field.value, '1');
    component.nudge(1);
    assert.equal(field.value, '2');
    assert.deepEqual(events, ['input', 'change', 'input', 'change']);
    assert.equal(component.ariaValue, 2);
});

test('decimal steps do not lose precision or introduce thousands separators', () => {
    const { component, field } = mount({ min: 0, step: 0.01, decimals: 2, separator: '.' }, '1234.56');
    component.nudge(1);
    assert.equal(field.value, '1234.57');
    component.nudge(-1);
    assert.equal(field.value, '1234.56');
    assert.equal(component.toNumber('1234.56oops'), null);
});

test('ordinary manual values and empty optional values survive blur', () => {
    const { component, field, events } = mount({ min: 0, decimals: 2, separator: '.' }, '12');
    component.normalize();
    assert.equal(field.value, '12');
    assert.deepEqual(events, []);
    field.value = '';
    component.normalize();
    assert.equal(field.value, '');
    assert.deepEqual(events, []);
});

test('typed mandatory numbers normalize empty values but not disabled or readonly fields', () => {
    const { component, field, events } = mount({ min: 1, nullable: false });
    component.normalize();
    assert.equal(field.value, '1');
    field.value = '';
    field.disabled = true;
    component.normalize();
    component.nudge(1);
    assert.equal(field.value, '');
    field.disabled = false;
    field.readOnly = true;
    component.normalize();
    component.nudge(1);
    assert.equal(field.value, '');
    assert.deepEqual(events, ['input', 'change']);
});

test('German comma typing is converted before the dot-decimal mask can discard it', () => {
    const { component, field, events } = mount({ decimals: 2, separator: '.' }, '12');
    let prevented = false;
    component.acceptDecimalSeparator({ type: 'beforeinput', inputType: 'insertText', data: ',', preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(field.value, '12.');
    assert.deepEqual(events, ['input']);
    assert.equal(component.ariaValue, 12);
});

test('plain decimal paste replaces a selected value without altering the scale', () => {
    const { component, field, events } = mount({ decimals: 2, separator: '.' }, '5.50');
    field.selectionStart = 0;
    field.selectionEnd = field.value.length;
    let prevented = false;
    component.acceptDecimalSeparator({ type: 'paste', clipboardData: { getData: () => '1234,56' }, preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    assert.equal(field.value, '1234.56');
    assert.equal(component.ariaValue, 1234.56);
    assert.deepEqual(events, ['input']);
});

test('existing comma based component consumers can also type a decimal dot', () => {
    const { component, field } = mount({ decimals: 2, separator: ',' }, '1234');
    component.acceptDecimalSeparator({ type: 'beforeinput', inputType: 'insertText', data: '.', preventDefault() {} });
    assert.equal(field.value, '1234,');
});

test('ARIA value refreshes after ordinary input and compact fields remain supported', () => {
    const { component, field } = mount({ decimals: 2, separator: '.' }, '10');
    component.$refs = {};
    field.value = '25.50';
    component.syncAria();
    assert.equal(component.ariaValue, 25.5);
    field.value = '';
    component.syncAria();
    assert.equal(component.ariaValue, null);
});
