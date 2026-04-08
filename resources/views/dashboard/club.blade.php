@extends('layouts.app')

@section('title', 'Панель клуба')

@section('content')
<div class="page-header">
    <div>
        <h2>Панель клуба — {{ $club->name ?? '' }}</h2>
        <p>Управление турнирами, кортами и участниками</p>
    </div>
</div>

<div class="card-dark">
    <div class="card-body">
        <p class="text-secondary mb-0">Здесь будет управление вашим клубом и турнирами.</p>
    </div>
</div>
@endsection