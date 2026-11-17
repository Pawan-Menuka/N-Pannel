@if (filled($seller['name']))<h1>{{ $seller['name'] }}</h1>@endif
@if (filled($seller['address']))<p class="multiline">{{ $seller['address'] }}</p>@endif
@php
    $contacts = array_filter([$seller['phone'], $seller['email']], fn (string $part): bool => filled($part));
@endphp
@if ($contacts !== [])<p>{{ implode(' · ', $contacts) }}</p>@endif
