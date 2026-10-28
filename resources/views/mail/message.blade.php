@php
    use App\Shared\Mail\EmailTheme as T;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $email->subject }}</title>
</head>
<body style="margin:0;padding:0;background:{{ T::BACKGROUND }};">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:{{ T::BACKGROUND }};">{{ $email->preheader }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{ T::BACKGROUND }};">
<tr><td align="center" style="padding:28px 16px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:{{ T::SURFACE }};border:1px solid {{ T::DIVIDER }};border-radius:14px;overflow:hidden;font-family:{!! T::FONT !!};color:{{ T::TEXT }};">
<tr><td style="height:3px;line-height:3px;font-size:0;background:{{ T::PRIMARY }};">&nbsp;</td></tr>
<tr><td style="padding:24px 32px 0 32px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
<td style="font-size:14.5px;font-weight:500;line-height:24px;color:{{ T::TEXT }};">
<img src="{{ url('/images/mail/logo.png') }}" width="24" height="24" alt="" style="vertical-align:middle;border:0;margin-right:8px;">BitFlag
</td>
<td align="right" style="font-family:{!! T::MONO !!};font-size:11px;font-weight:500;letter-spacing:0.08em;text-transform:uppercase;color:{{ T::MUTED }};">{{ $email->kicker }}</td>
</tr></table>
</td></tr>
<tr><td style="padding:28px 32px 8px 32px;">
<h1 style="margin:0 0 18px 0;font-size:24px;line-height:31px;font-weight:500;color:{{ T::TEXT }};">{{ $email->title }}</h1>
@if ($email->greeting !== null)
<p style="margin:0 0 12px 0;font-size:15px;line-height:24px;color:#2f2f35;">{{ $email->greeting }}</p>
@endif
@foreach ($email->paragraphs as $paragraph)
<p style="margin:0 0 12px 0;font-size:15px;line-height:24px;color:#2f2f35;">{{ $paragraph }}</p>
@endforeach
@if ($email->facts !== [])
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 18px 0;border:1px solid {{ T::DIVIDER }};border-radius:10px;">
@foreach ($email->facts as $fact)
<tr>
<td valign="top" style="padding:9px 16px;width:110px;font-size:13px;line-height:20px;color:{{ T::MUTED }};@if (! $loop->first) border-top:1px solid {{ T::DIVIDER }}; @endif">{{ $fact['label'] }}</td>
<td valign="top" style="padding:9px 16px 9px 0;font-size:14px;line-height:20px;color:{{ T::TEXT }};@if (! $loop->first) border-top:1px solid {{ T::DIVIDER }}; @endif">{{ $fact['value'] }}</td>
</tr>
@endforeach
</table>
@endif
@if ($email->panel !== null)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 18px 0;border:1px solid {{ T::DIVIDER }};border-radius:10px;">
<tr><td style="padding:14px 16px;">
<div style="font-size:12px;line-height:18px;letter-spacing:0.06em;text-transform:uppercase;color:{{ T::MUTED }};">{{ $email->panel['heading'] }}</div>
@foreach ($email->panel['items'] as $item)
<div style="padding-top:8px;font-size:14px;line-height:20px;color:{{ T::TEXT }};"><span style="color:{{ T::PRIMARY }};">&#10003;</span>&nbsp; {{ $item }}</div>
@endforeach
</td></tr>
</table>
@endif
@if ($email->button !== null)
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:6px 0 18px 0;"><tr>
<td style="border-radius:8px;background:{{ T::PRIMARY }};"><a href="{{ $email->button['url'] }}" style="display:inline-block;padding:10px 18px;border-radius:8px;background:{{ T::PRIMARY }};font-size:14px;line-height:20px;font-weight:500;color:{{ T::PRIMARY_FOREGROUND }};text-decoration:none;">{{ $email->button['label'] }}</a></td>
</tr></table>
@endif
@if ($email->note !== null)
<p style="margin:0 0 18px 0;font-size:13px;line-height:20px;color:#68686f;">{{ $email->note }}</p>
@endif
@if ($email->callout !== null)
@php
    $warning = $email->callout['tone'] === 'warning';
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px 0;border:1px solid {{ $warning ? T::WARNING_BORDER : T::DIVIDER }};border-radius:10px;background:{{ $warning ? T::WARNING_SUBTLE : T::SURFACE }};">
<tr><td style="padding:12px 16px;font-size:13.5px;line-height:21px;color:{{ $warning ? T::WARNING_TEXT : T::TEXT }};">{{ $email->callout['text'] }}</td></tr>
</table>
@endif
</td></tr>
<tr><td style="padding:14px 32px 26px 32px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="border-top:1px solid {{ T::DIVIDER }};padding-top:18px;font-size:12px;line-height:18px;color:#7a7a82;">
<div>{{ $email->reason }}</div>
<div style="padding-top:6px;">{{ 'BitFlag'.($email->recipient !== null ? ' · Sent to '.$email->recipient : '') }}</div>
</td></tr></table>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
