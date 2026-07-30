{{-- shadcn/ui Button, versi Blade. Tidak ada React di proyek ini — yang dipakai
     ulang cuma resep kelasnya. `as` dipakai buat <a> yang tampil sebagai tombol. --}}
@props(['variant' => 'default', 'size' => 'default', 'as' => 'button'])

@php
    $variants = [
        'default' => 'bg-primary text-primary-foreground shadow-xs hover:bg-primary/90',
        'secondary' => 'bg-secondary text-secondary-foreground shadow-xs hover:bg-secondary/80',
        'outline' => 'border border-input bg-background shadow-xs hover:bg-accent hover:text-accent-foreground',
        'ghost' => 'hover:bg-accent hover:text-accent-foreground',
        'destructive' => 'bg-destructive text-destructive-foreground shadow-xs hover:bg-destructive/90',
        'link' => 'text-primary underline-offset-4 hover:underline',
    ];
    $sizes = [
        'sm' => 'h-8 gap-1.5 rounded-md px-3 text-xs',
        'default' => 'h-9 px-4 py-2',
        'lg' => 'h-10 rounded-md px-6',
        'icon' => 'size-9',
    ];
    $base = 'inline-flex shrink-0 cursor-pointer items-center justify-center gap-2 whitespace-nowrap rounded-md '
        .'text-sm font-medium transition-all outline-none focus-visible:border-ring focus-visible:ring-[3px] '
        .'focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 [&_svg]:size-4';
@endphp

<{{ $as }} {{ $attributes->class([$base, $variants[$variant], $sizes[$size]]) }}>{{ $slot }}</{{ $as }}>
