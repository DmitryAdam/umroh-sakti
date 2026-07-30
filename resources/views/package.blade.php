@extends('layout')
@section('title', 'Detail Paket')

@section('content')
<x-ui.button as="a" variant="ghost" size="sm" href="{{ route('search') }}" class="-ml-3">&larr; Kembali</x-ui.button>

<x-ui.card as="article" class="mx-auto mt-3 max-w-3xl p-6">
    @include('partials.detail')
</x-ui.card>
@endsection
