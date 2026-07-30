{{-- Label + kontrol. Pakai <label> supaya klik label memfokus kontrolnya. --}}
@props(['label'])

<label {{ $attributes->class('grid gap-1.5') }}>
    <span class="text-xs font-medium leading-none text-muted-foreground">{{ $label }}</span>
    {{ $slot }}
</label>
