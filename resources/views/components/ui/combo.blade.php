{{-- Combobox pengganti <select> native: menu bawaan browser tidak bisa distyle
     (tinggi baris, font, jumlah opsi terlihat) dan daftar panjang seperti akun
     (~190) tidak bisa dicari. Bukan library — satu blok JS di bawah, dipakai
     ulang semua instance lewat event delegation.

     `submit` = pilihan langsung men-submit form induknya (pengganti
     onchange="this.form.submit()"). `store` = tidak ikut form sama sekali,
     nilainya dibaca JS halaman (tampilan grid/daftar, jumlah kolom). --}}
@props([
    'name' => null,
    'options' => [],
    'value' => '',
    'placeholder' => 'semua',
    'icon' => null,
    'submit' => false,
    'store' => null,
    'size' => 'sm',
])

@php
    $options = collect($options);
    $value = (string) $value;
    $label = $value !== '' ? ($options[$value] ?? $value) : $placeholder;
    // Kotak cari cuma untuk daftar yang tidak bisa dipindai mata sekali lihat.
    $cari = $options->count() > 8;
    $tinggi = $size === 'lg' ? 'h-10 text-sm' : 'h-9 text-xs';
    $item = 'flex w-full cursor-pointer items-center justify-between gap-2 rounded-sm px-2 py-1.5 text-left text-xs '
        .'hover:bg-accent hover:text-accent-foreground aria-selected:bg-accent aria-selected:font-medium';
@endphp

<div data-combo {{ $attributes->class('relative min-w-0') }}>
    <input type="hidden" @if ($name) name="{{ $name }}" @endif
           @if ($store) data-store="{{ $store }}" @endif
           @if ($submit) data-submit @endif value="{{ $value }}">

    <button type="button" data-combo-button
            class="{{ $tinggi }} flex w-full cursor-pointer items-center gap-1.5 rounded-xl border border-input bg-background px-2.5 shadow-xs outline-none hover:bg-accent/40 focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50">
        @if ($icon)<x-ui.icon :name="$icon" class="text-muted-foreground" />@endif
        <span data-combo-label class="truncate @if ($value === '') text-muted-foreground @endif">{{ $label }}</span>
        <x-ui.icon name="chevron" class="ml-auto opacity-50" />
    </button>

    <div data-combo-menu hidden
         class="absolute left-0 top-full z-50 mt-1 w-max min-w-full max-w-[min(20rem,80vw)] rounded-xl border bg-popover p-1 text-popover-foreground shadow-lg">
        @if ($cari)
            <input data-combo-search type="text" placeholder="cari…" autocomplete="off"
                   class="mb-1 w-full rounded-md border border-input bg-background px-2 py-1 text-xs outline-none focus:border-ring">
        @endif
        <ul data-combo-list class="max-h-64 overflow-y-auto">
            <li><button type="button" data-value="" class="{{ $item }} text-muted-foreground" @if ($value === '') aria-selected="true" @endif>{{ $placeholder }}</button></li>
            @foreach ($options as $val => $lbl)
                <li><button type="button" data-value="{{ $val }}" class="{{ $item }}" @if ($value === (string) $val) aria-selected="true" @endif>{{ $lbl }}</button></li>
            @endforeach
        </ul>
    </div>
</div>

@once
<script>
// Satu listener untuk semua combo di halaman — instance baru (mis. hasil render
// ulang) ikut jalan tanpa inisialisasi.
const comboTutup = (kecuali) => document.querySelectorAll('[data-combo]').forEach((c) => {
    if (c !== kecuali) c.querySelector('[data-combo-menu]').hidden = true;
});

document.addEventListener('click', (event) => {
    const combo = event.target.closest('[data-combo]');
    comboTutup(combo);
    if (!combo) return;

    const menu = combo.querySelector('[data-combo-menu]');
    if (event.target.closest('[data-combo-button]')) {
        menu.hidden = !menu.hidden;
        if (!menu.hidden) menu.querySelector('[data-combo-search]')?.focus();
        return;
    }

    const opsi = event.target.closest('[data-value]');
    if (!opsi) return;

    comboPilih(combo.querySelector('input[type=hidden]'), opsi.dataset.value);
    menu.hidden = true;
});

// Dipakai juga saat memulihkan nilai dari localStorage (tampilan & kolom).
function comboPilih(input, nilai) {
    const combo = input.closest('[data-combo]');
    const opsi = combo.querySelector(`[data-value="${nilai}"]`) ?? combo.querySelector('[data-value=""]');
    const label = combo.querySelector('[data-combo-label]');

    combo.querySelectorAll('[data-value]').forEach((b) => b.removeAttribute('aria-selected'));
    opsi.setAttribute('aria-selected', 'true');
    label.textContent = opsi.textContent.trim();
    label.classList.toggle('text-muted-foreground', opsi.dataset.value === '');
    input.value = opsi.dataset.value;
    input.dispatchEvent(new Event('change', { bubbles: true }));

    if (input.hasAttribute('data-submit')) input.form?.requestSubmit();
}

document.addEventListener('input', (event) => {
    const cari = event.target.closest('[data-combo-search]');
    if (!cari) return;
    const q = cari.value.toLowerCase();
    cari.closest('[data-combo]').querySelectorAll('[data-combo-list] li').forEach((li) => {
        li.hidden = !li.textContent.toLowerCase().includes(q);
    });
});

document.addEventListener('keydown', (event) => event.key === 'Escape' && comboTutup(null));
</script>
@endonce
