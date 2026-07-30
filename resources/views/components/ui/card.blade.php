@props(['as' => 'div'])

<{{ $as }} {{ $attributes->class('rounded-xl border bg-card text-card-foreground shadow-sm') }}>{{ $slot }}</{{ $as }}>
