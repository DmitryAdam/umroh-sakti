{{-- Status satu post, satu definisi. Dipakai kolom status di /posts dan daftar
     "usulan saya" di /suggestions — dua halaman yang membaca himpunan yang sama
     (`PostController::kumpulkan()`), jadi dua salinan berarti dua tempat yang bisa
     menyebut hal berbeda tentang baris yang sama.

     Yang TIDAK ikut ke sini: select status paket di /posts. Itu aksi admin
     (`PATCH /packages/{id}/status`), bukan keterangan. --}}
@if ($post['alasan'])
    <x-ui.badge variant="destructive" title="alasan di excluded_posts">{{ $post['alasan'] }}</x-ui.badge>
@endif

@if ($post['usulan'])
    {{-- Rawnya sudah ada tapi belum pernah dikirim ke model. Di /posts, "baca ulang
         AI" itu tombol setujuinya dan "blokir" itu tolaknya. --}}
    <x-ui.badge variant="outline" title="diusulkan {{ $post['usulan'] }} — belum dibaca AI">menunggu admin</x-ui.badge>
@elseif ($post['paket']->isNotEmpty())
    <x-ui.badge title="sudah jadi baris paket">{{ $post['paket']->count() }} paket</x-ui.badge>
@elseif (! $post['alasan'])
    <x-ui.badge variant="secondary" title="sudah disetujui, menunggu antrian ai">dibaca AI</x-ui.badge>
@endif
