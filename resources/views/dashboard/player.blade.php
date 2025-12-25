@extends('layouts.app')

@section('title', 'Главная')

@section('content')
<div class="container-fluid">
    <h2 class="mb-4">Добро пожаловать, {{ auth()->user()->first_name }}!</h2>

    <div class="row">
        <div class="col-md-4 mb-4">
            <div class="card">
                <div class="card-body text-center">
                    <h1 class="display-4 text-primary">{{ auth()->user()->rating }}</h1>
                    <p class="text-muted mb-0">Рейтинг</p>
                </div>
            </div>
        </div>

        <div class="col-md-4 mb-4">
            <div class="card">
                <div class="card-body text-center">
                    <h1 class="display-4 text-success">{{ auth()->user()->level }}</h1>
                    <p class="text-muted mb-0">Уровень ({{ auth()->user()->level_name }})</p>
                </div>
            </div>
        </div>

        <div class="col-md-4 mb-4">
            <div class="card">
                <div class="card-body text-center">
                    <h1 class="display-4 text-info">0</h1>
                    <p class="text-muted mb-0">Матчей сыграно</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">Ближайшие турниры</h5>
        </div>
        <div class="card-body">
            <p class="text-muted">Пока нет турниров. Скоро здесь появятся!</p>
        </div>
    </div>
</div>
@endsection