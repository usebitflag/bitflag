{!! $email->title !!}

@if ($email->greeting !== null)
{!! $email->greeting !!}

@endif
@foreach ($email->paragraphs as $paragraph)
{!! $paragraph !!}

@endforeach
@foreach ($email->facts as $fact)
{!! $fact['label'] !!}: {!! $fact['value'] !!}
@endforeach
@if ($email->facts !== [])

@endif
@if ($email->panel !== null)
{!! $email->panel['heading'] !!}:
@foreach ($email->panel['items'] as $item)
- {!! $item !!}
@endforeach

@endif
@if ($email->button !== null)
{!! $email->button['label'] !!}: {!! $email->button['url'] !!}

@endif
@if ($email->note !== null)
{!! $email->note !!}

@endif
@if ($email->callout !== null)
{!! $email->callout['text'] !!}

@endif
--
{!! $email->reason !!}
{!! 'BitFlag'.($email->recipient !== null ? ' · Sent to '.$email->recipient : '') !!}
