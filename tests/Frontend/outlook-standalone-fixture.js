export const STANDALONE_VERSION = '0123456789abcdef';
const metadata = text => `<span hidden aria-hidden="true" style="display:none!important;mso-hide:all;font-size:0;line-height:0;">${text}</span>`;
const table = (classes, content, extra = '') => `<table class="${classes}" ${extra}><tbody><tr><td>${content}</td></tr></tbody></table>`;

export function standaloneHtml(version = STANDALONE_VERSION) {
    const person = table('rt-personal-left', '<p style="font-size:22px;">Synthetic person</p><p>Role</p>');
    const company = table('rt-personal-right', '<img src="cid:logo.png" width="200"><p>Synthetic company</p>');
    const train = table('rt-df-train', '<div class="rt-train-window"><img class="rt-delivery-train" src="cid:train.gif" width="300" height="38"></div><!--[if mso]><img src="cid:still.png" width="220" height="28"><![endif]-->');
    const flow = table('rt-personal-columns', person + company + train, 'data-rt-personal-flow="v1" data-rt-personal-columns="v1" data-rt-desktop-flow="outlook-desktop-flow-v4"');
    const header = '<table><tbody><tr><td class="design-hotline-row">24/7 erreichbar</td></tr></tbody></table>';
    return '<div class="rt-outlook-signature rts0123456789"><table class="rt-managed-signature-v1 rts0123456789"><tbody><tr><td>' + header + flow
        + '</td></tr><tr><td style="color:#718096;"><p>Legal</p>' + metadata('RT-SIGNATURE-MANAGED-V1') + metadata('RT-SIGNATURE-VERSION:' + version) + '</td></tr></tbody></table></div>';
}

export function nativeOfficeHtml(html) {
    return html.replace(/<!--[\s\S]*?-->/g, '').replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, '')
        .replace(/\s(?:class|data-rt-[a-z-]+|aria-hidden)="[^"]*"/gi, '').replace(/\shidden(?=\s|>)/gi, '')
        .replace(/id="(rt-native-layout-[^"]*)"/g, 'id="x_$1"');
}

export function officeCarrier(html) {
    return html.replace(/id="(rt-native-layout-[^"]*)"/g, 'id="x_$1"').replace(/#rt-native-layout-/g, '#x_rt-native-layout-').replace(/;}/g, '}');
}
