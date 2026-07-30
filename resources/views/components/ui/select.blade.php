{{-- Select native yang tampil seperti shadcn Select (Radix-nya butuh React).
     Panah bawaan dimatikan, diganti chevron sendiri biar tingginya seragam. --}}
<div class="relative">
    <select {{ $attributes->class(
        'flex h-9 w-full appearance-none items-center rounded-md border border-input bg-transparent py-1 pl-3 pr-8 '
        .'text-sm shadow-xs transition-[color,box-shadow] outline-none '
        .'focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50'
    ) }}>{{ $slot }}</select>
    <svg class="pointer-events-none absolute right-2.5 top-1/2 size-4 -translate-y-1/2 opacity-50"
         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="m6 9 6 6 6-6"/>
    </svg>
</div>
