{{-- Временная заглушка вьюхи. Полноценная вёрстка — в следующей задаче. --}}
@extends('layouts.app')

@section('content')
    <h1>Инвентарь</h1>
    <ul>
        @foreach($items as $item)
            <li>{{ $item->name }} — {{ $item->price }}</li>
        @endforeach
    </ul>
@endsection
