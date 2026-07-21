@php($company = \App\Support\CompanyData::all())
<tr>
<td class="rt-pad" bgcolor="#080b10" style="padding:22px 34px;background:#080b10;border-top:5px solid #e4002b;">
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
<tbody>
<tr>
<td colspan="2" style="padding-bottom:18px;">
<a href="{{ $company['website'] ?: config('app.url') }}" style="display:inline-block;">
<img class="rt-logo" src="{{ asset('rt-brand/img/logo-mail-dark.png') }}" width="220" alt="{{ $company['name'] }}" style="display:block;width:220px;max-width:100%;height:auto;border:0;">
</a>
</td>
</tr>
<tr class="rt-stack">
<td style="color:#8e98a5;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:18px;">{{ $company['name'] }} &middot; {{ \App\Support\CompanyData::addressLine($company) }}</td>
<td align="right" style="color:#ff5570;font-family:Consolas,'Courier New',monospace;font-size:9px;line-height:16px;font-weight:bold;letter-spacing:1px;">@if($company['emergency_phone']) 24/7 &middot; {{ $company['emergency_phone'] }} @endif</td>
</tr>
@if($company['managing_directors'] || $company['register_court'] || $company['commercial_register_number'] || $company['vat_id'] || $company['tax_number'])
<tr>
<td colspan="2" style="padding-top:10px;color:#69737f;font-family:Arial,Helvetica,sans-serif;font-size:9px;line-height:15px;">
@if($company['managing_directors']) Geschäftsführung: {{ $company['managing_directors'] }}@endif
@if($company['register_court']) &middot; Registergericht: {{ $company['register_court'] }}@endif
@if($company['commercial_register_number']) &middot; HRB {{ $company['commercial_register_number'] }}@endif
@if($company['vat_id']) &middot; USt-IdNr. {{ $company['vat_id'] }}@endif
@if($company['tax_number']) &middot; Steuernummer {{ $company['tax_number'] }}@endif
</td>
</tr>
@endif
</tbody>
</table>
</td>
</tr>
<tr>
<td class="rt-pad" bgcolor="#10161e" style="padding:15px 34px;background:#10161e;color:#77818d;font-family:Arial,Helvetica,sans-serif;font-size:10px;line-height:16px;">
{{ Illuminate\Mail\Markdown::parse($slot) }}
</td>
</tr>
