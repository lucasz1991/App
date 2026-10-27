export const STANDALONE_VERSION = '0123456789abcdef';
const metadata = text => `<span hidden aria-hidden="true" style="display:none!important;mso-hide:all;font-size:0;line-height:0;">${text}</span>`;
const table = (classes, content, extra = '') => `<table class="${classes}" ${extra}><tbody><tr><td>${content}</td></tr></tbody></table>`;

export function standaloneHtml(version = STANDALONE_VERSION, responsive = true, wrappers = false) {
    const person = table('rt-personal-left', '<p style="font-size:22px;">Synthetic person</p><p>Role</p>', responsive ? 'align="left" height="50"' : '');
    const company = table('rt-personal-right', '<img src="cid:logo.png" width="200"><p>Synthetic company</p>');
    const train = table('rt-df-train', '<div class="rt-train-window"><img class="rt-delivery-train" src="cid:train.gif" width="300" height="' + (responsive ? '65' : '38') + '"></div><!--[if mso]><img src="cid:still.png" width="220" height="28"><![endif]-->');
    const logo = responsive ? table('rt-personal-logo-column', table('rt-personal-logo-frame', '<img src="cid:separate-logo.png" width="200" height="34">')) : '';
    const content = responsive ? logo + person + company + '</td></tr><tr><td>' + train : person + company + train;
    const flow = table('rt-personal-columns', content, 'data-rt-personal-flow="v1" data-rt-personal-columns="v1" data-rt-desktop-flow="outlook-desktop-flow-v' + (responsive ? '8' : '4') + '"');
    const header = '<table><tbody><tr><td class="design-hotline-row">' + (wrappers ? table('rt-brand-hotline-gutter', '<img src="cid:fill.png" width="100%" height="34">') : '') + '24/7 erreichbar</td></tr></tbody></table>';
    return '<div class="rt-outlook-signature rts0123456789 rt-dflow-host-v4 rt-df' + (responsive ? '8' : '4') + '0123456789"><table class="rt-managed-signature-v1 rts0123456789"><tbody><tr><td>' + header + flow
        + '</td></tr><tr><td style="color:#718096;">' + (wrappers ? table('rt-brand-legal-table', '<p>Legal</p>') : '<p>Legal</p>') + metadata('RT-SIGNATURE-MANAGED-V1') + metadata('RT-SIGNATURE-VERSION:' + version) + '</td></tr></tbody></table></div>';
}

export function nativeOfficeHtml(html) {
    return html.replace(/<!--[\s\S]*?-->/g, '').replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, '')
        .replace(/\s(?:class|data-rt-[a-z-]+|aria-hidden)="[^"]*"/gi, '').replace(/\shidden(?=\s|>)/gi, '')
        .replace(/id="(rt-native-layout-[^"]*)"/g, 'id="x_$1"');
}

export function officeCarrier(html) {
    return html.replace(/id="(rt-native-layout-[^"]*)"/g, 'id="x_$1"').replace(/#rt-native-layout-/g, '#x_rt-native-layout-').replace(/;}/g, '}');
}
