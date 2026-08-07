<table role="presentation" dir="ltr" border="0" cellspacing="0" cellpadding="0" style="direction:ltr;margin-left:{{ ($align ?? 'left') === 'right' ? 'auto' : '0' }};margin-right:{{ ($align ?? 'left') === 'right' ? '0' : 'auto' }};margin-top:7px;border-collapse:collapse;text-align:left;">
    <tr>
        <td width="22" align="center" valign="top" class="rt-company-contact-icon" style="width:22px;padding:0 0 7px;font-size:0;line-height:0;mso-line-height-rule:exactly;text-align:center;"><img src="{{ $values['ICON_LOCATION_SRC'] }}" width="22" height="22" alt="" style="display:block;width:22px;height:22px;margin:0 auto;"></td>
        <td valign="top" class="rt-company-contact-text" style="padding:1px 0 7px 9px;color:{{ $values['SIGNATURE_META_TEXT'] }};font-size:11px;line-height:16px;">{{ $values['FIRMENSTRASSE'] }}<br>{{ $values['FIRMEN_PLZ_ORT'] }}</td>
    </tr>
    <!-- RT_COMPANY_PHONE_START -->
    <tr>
        <td width="22" align="center" valign="middle" class="rt-company-contact-icon" style="width:22px;padding:0 0 7px;font-size:0;line-height:0;mso-line-height-rule:exactly;text-align:center;"><img src="{{ $values['ICON_PHONE_SRC'] }}" width="22" height="22" alt="" style="display:block;width:22px;height:22px;margin:0 auto;"></td>
        <td valign="middle" class="rt-company-contact-text" style="padding:0 0 7px 9px;color:{{ $values['SIGNATURE_META_TEXT'] }};font-size:11px;line-height:16px;"><a href="tel:{{ $values['FIRMEN_TELEFON_TEL'] }}" style="color:{{ $values['SIGNATURE_TEXT_PRIMARY'] }};text-decoration:none;">{{ $values['FIRMEN_TELEFON'] }}</a></td>
    </tr>
    <!-- RT_COMPANY_PHONE_END -->
    <tr>
        <td width="22" align="center" valign="middle" class="rt-company-contact-icon" style="width:22px;padding:0;font-size:0;line-height:0;mso-line-height-rule:exactly;text-align:center;"><img src="{{ $values['ICON_EMAIL_SRC'] }}" width="22" height="22" alt="" style="display:block;width:22px;height:22px;margin:0 auto;"></td>
        <td valign="middle" class="rt-company-contact-text" style="padding:0 0 0 9px;color:{{ $values['SIGNATURE_META_TEXT'] }};font-size:11px;line-height:16px;"><a href="mailto:{{ $values['FIRMEN_EMAIL'] }}" style="color:{{ $values['SIGNATURE_TEXT_PRIMARY'] }};text-decoration:none;">{{ $values['FIRMEN_EMAIL'] }}</a></td>
    </tr>
</table>
