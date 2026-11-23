@props(['url', 'logo' => null, 'name' => 'ClientHub'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if ($logo)
<img src="{{ $logo }}" class="logo" alt="{{ $name }}" style="max-height: 48px; width: auto;">
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>