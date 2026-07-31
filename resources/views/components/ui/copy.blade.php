{{-- Teks + tombol salin. `onclick` inline, bukan listener: partial detail
     disuntik lewat innerHTML di lightbox, dan <script> di dalam innerHTML tidak
     pernah dieksekusi browser. Umpan baliknya CSS murni (`data-copied` +
     varian group), jadi tidak ada timer yang perlu dibersihkan. --}}
@props(['text', 'title' => 'salin'])

<button type="button" title="{{ $title }}"
        onclick="navigator.clipboard.writeText(this.dataset.copy).then(() => this.dataset.copied = '')"
        data-copy="{{ $text }}"
        {{ $attributes->class('group inline-flex items-center gap-1 rounded-md px-1 -mx-1 font-mono hover:bg-accent hover:text-accent-foreground') }}>
    {{ $slot->isEmpty() ? $text : $slot }}
    <x-ui.icon name="copy" class="opacity-50 group-data-[copied]:hidden" />
    <x-ui.icon name="check" class="hidden text-primary group-data-[copied]:block" />
</button>
