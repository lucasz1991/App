import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const read = (path) => readFile(new URL(path, import.meta.url), 'utf8');

test('premium login is opt-in and preserves the real authentication inputs', async () => {
    const [login, adminLogin, shell, toggle, input] = await Promise.all([
        read('../../resources/views/auth/login.blade.php'),
        read('../../resources/views/auth/admin-login.blade.php'),
        read('../../resources/views/components/auth-brand-layout.blade.php'),
        read('../../resources/views/components/ui/forms/toggle-button.blade.php'),
        read('../../resources/views/components/ui/forms/input.blade.php'),
    ]);

    assert.match(login, /variant="premium-login"/);
    assert.match(login, /method="POST"[\s\S]*?action="\{\{ route\('login'\) \}\}"/);
    assert.match(login, /@csrf/);
    assert.match(login, /name="email"[\s\S]*?autocomplete="username"/);
    assert.match(login, /name="password"[\s\S]*?autocomplete="current-password"/);
    assert.match(login, /aria-describedby="email-error"[\s\S]*?id="email-error"/);
    assert.match(login, /aria-describedby="password-error"[\s\S]*?id="password-error"/);
    assert.match(login, /name="remember"/);
    assert.match(login, /<x-ui\.forms\.toggle-button[\s\S]*?name="remember"/);
    assert.doesNotMatch(login, /remember-help|remember_me_hint|auth_secure_hint|login_description/);
    assert.match(login, /route\('password\.request'\)/);
    assert.match(login, /<x-ui\.forms\.input[\s\S]*?type="email"[\s\S]*?:label="__\('app\.email'\)"/);
    assert.match(login, /<x-ui\.forms\.input[\s\S]*?type="password"[\s\S]*?:label="__\('app\.password'\)"/);
    assert.match(input, /x-bind:type="passwordVisible \? 'text' : 'password'"/);
    assert.match(input, /x-bind:aria-label="passwordVisible/);
    assert.match(input, /rt-ui-field-control/);
    assert.match(input, /rt-login-field__input/);
    assert.match(shell, /data-auth-variant="\{\{ \$premiumLogin \? 'premium-login' : 'standard' \}\}"/);
    assert.match(shell, /data-rt-logo-3d/);
    assert.doesNotMatch(shell, /rt-auth__compact-brand/);
    assert.match(toggle, /role="switch"/);
    assert.match(toggle, /rt-ui-toggle-control--\{\{ \$resolvedSize \}\}/);
    assert.doesNotMatch(adminLogin, /variant="premium-login"/);
});

test('floating fields cover focus, filled and browser autofill states', async () => {
    const styles = await read('../../public/rt-brand/rt-auth.css');

    assert.match(styles, /\.rt-login-field__input:focus ~ \.rt-login-field__label/);
    assert.match(styles, /\.rt-login-field__input:not\(:placeholder-shown\) ~ \.rt-login-field__label/);
    assert.match(styles, /\.rt-login-field__input:-webkit-autofill ~ \.rt-login-field__label/);
    assert.match(styles, /\.rt-login-field__input\[aria-invalid='true'\]/);
    assert.match(styles, /min-height:\s*3\.35rem/);
    assert.match(styles, /\.rt-login-field__toggle\s*\{[\s\S]*?width:\s*2\.75rem;[\s\S]*?height:\s*2\.75rem/);
});

test('premium auth motion and mobile geometry remain bounded and optional', async () => {
    const [styles, appStyles, guest] = await Promise.all([
        read('../../public/rt-brand/rt-auth.css'),
        read('../../resources/css/app.css'),
        read('../../resources/views/layouts/guest.blade.php'),
    ]);

    assert.match(styles, /\.rt-auth--premium-login\s*\{[\s\S]*?min-height:\s*100dvh/);
    assert.match(styles, /@media \(max-width: 479\.98px\)/);
    assert.match(styles, /@media \(prefers-reduced-motion: reduce\)[\s\S]*?\.rt-auth-card__surface--premium/);
    assert.match(styles, /\.rt-login-submit:focus-visible/);
    assert.match(styles, /\.rt-auth--premium-login \.rt-logo-stage/);
    assert.doesNotMatch(styles, /rt-auth__compact-brand/);
    assert.match(appStyles, /\.rt-ui-toggle-control::before/);
    assert.match(appStyles, /\.rt-ui-toggle-control::before\s*\{[\s\S]*?top:\s*50%;[\s\S]*?translateY\(-50%\)/);
    assert.match(appStyles, /\.rt-ui-toggle-control::after\s*\{[\s\S]*?width:\s*\.5rem;[\s\S]*?linear-gradient\(var\(--rt-toggle-off-icon\)/);
    assert.match(appStyles, /translate\(-50%, -50%\) rotate\(45deg\)/);
    assert.match(appStyles, /input:checked \+ \.rt-ui-toggle-control::before[\s\S]*?translate\(var\(--rt-toggle-travel\), -50%\)/);
    assert.match(appStyles, /input:checked \+ \.rt-ui-toggle-control::after[\s\S]*?border-right-width:\s*2px;[\s\S]*?border-bottom-width:\s*2px/);
    assert.match(appStyles, /--rt-toggle-spring:\s*cubic-bezier\(\.34, 1\.56, \.64, 1\)/);
    assert.match(appStyles, /transform \.52s var\(--rt-toggle-spring\)/);
    assert.match(appStyles, /@media \(prefers-reduced-motion: reduce\)[\s\S]*?\.rt-ui-toggle-control::before/);
    assert.match(styles, /\.rt-login-forgot\s*\{[\s\S]*?padding:\s*\.35rem \.75rem/);
    assert.match(guest, /rt-auth\.css'\) \}\}\?v=20260904-toggle-alignment/);
});

test('global form field components use the shared premium surface system', async () => {
    const paths = [
        '../../resources/views/components/ui/forms/input.blade.php',
        '../../resources/views/components/ui/forms/textarea.blade.php',
        '../../resources/views/components/ui/forms/select.blade.php',
        '../../resources/views/components/ui/forms/date-input.blade.php',
        '../../resources/views/components/ui/forms/time-input.blade.php',
    ];
    const [components, numberInput, dateField, checkbox, radioGroup, radioItem, styles] = await Promise.all([
        Promise.all(paths.map(read)),
        read('../../resources/views/components/ui/forms/number-input.blade.php'),
        read('../../resources/views/components/ui/forms/date-field.blade.php'),
        read('../../resources/views/components/ui/forms/checkbox.blade.php'),
        read('../../resources/views/components/ui/forms/radio-btn-group.blade.php'),
        read('../../resources/views/components/ui/forms/radio-btn-group-item.blade.php'),
        read('../../resources/css/app.css'),
    ]);

    components.forEach(component => assert.match(component, /rt-ui-field-control/));
    assert.match(numberInput, /rt-ui-field-shell/);
    assert.match(dateField, /rt-ui-field-shell/);
    assert.match(checkbox, /rt-ui-check-field/);
    assert.match(radioGroup, /rt-ui-radio-group/);
    assert.match(radioItem, /rt-ui-radio-item/);
    assert.match(styles, /\/\* Premium-Formsystem/);
    assert.match(styles, /\.rt-ui-field-control,[\s\S]*?min-height:\s*3\.35rem/);
    assert.match(styles, /\.rt-ui-checkbox::before[\s\S]*?cubic-bezier\(\.34, 1\.56, \.64, 1\)/);
    assert.match(styles, /html\.dark \.rt-ui-field-control/);
});
