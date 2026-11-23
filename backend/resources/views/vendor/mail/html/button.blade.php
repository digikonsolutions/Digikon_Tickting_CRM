@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
    'brandColor' => null,
])
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table border="0" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td>
<a href="{{ $url }}" class="button button-{{ $color }}" target="_blank" rel="noopener"@if ($brandColor && $color === 'primary') style="background-color: {{ $brandColor }}; border-top: 10px solid {{ $brandColor }}; border-right: 18px solid {{ $brandColor }}; border-bottom: 10px solid {{ $brandColor }}; border-left: 18px solid {{ $brandColor }};"@endif>{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>
