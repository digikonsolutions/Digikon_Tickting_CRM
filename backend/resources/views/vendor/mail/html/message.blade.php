@props(['brand' => null])
{{-- Brand: an agency's name, logo and colour for agency users; the ClientHub platform brand for everyone else. --}}
<x-mail::layout :brand="$brand">
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="$brand['url'] ?? config('app.frontend_url', config('app.url'))" :logo="$brand['logo_url'] ?? null" :name="$brand['name'] ?? 'ClientHub'">
{{ $brand['name'] ?? 'ClientHub' }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
&copy; {{ date('Y') }} {{ $brand['name'] ?? 'ClientHub' }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>