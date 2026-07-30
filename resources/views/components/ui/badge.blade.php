@props(['variant' => 'secondary'])

@php
    $variants = [
        'default' => 'border-transparent bg-primary text-primary-foreground',
        'secondary' => 'border-transparent bg-secondary text-secondary-foreground',
        'outline' => 'text-foreground',
        'destructive' => 'border-transparent bg-destructive text-destructive-foreground',
    ];
@endphp

<span {{ $attributes->class([
    'inline-flex w-fit shrink-0 items-center gap-1 whitespace-nowrap rounded-md border px-2 py-0.5 text-xs font-medium',
    $variants[$variant],
]) }}>{{ $slot }}</span>
