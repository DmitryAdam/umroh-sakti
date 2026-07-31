{{-- Hasil aksi + galat validasi. Di-include di tiap halaman alat kerja; jangan
     ditulis ulang per halaman — bentuknya sudah pernah menyimpang tiga kali. --}}
@if (session('status'))
    <x-ui.alert>{{ session('status') }}</x-ui.alert>
@endif

@if ($errors->any())
    <x-ui.alert variant="error">
        <ul class="list-inside list-disc">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </x-ui.alert>
@endif
