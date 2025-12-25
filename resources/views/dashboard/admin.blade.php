@extends('layouts.app')

@section('title', 'Панель супер-админа')

@section('content')
<div class="container-fluid">
    <h2 class="mb-4">Панель супер-админа</h2>

    <div class="row">
        <div class="col-md-3 mb-4">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <h3>{{ \App\Models\User::count() }}</h3>
                    <p class="mb-0">Игроков</p>
                </div>
            </div>
        </div>

        <div class="col-md-3 mb-4">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <h3>{{ \App\Models\Club::count() }}</h3>
                    <p class="mb-0">Клубов</p>
                </div>
            </div>
        </div>

        <div class="col-md-3 mb-4">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <h3>0</h3>
                    <p class="mb-0">Турниров</p>
                </div>
            </div>
        </div>

        <div class="col-md-3 mb-4">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <h3>0</h3>
                    <p class="mb-0">Матчей</p>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Быстрые действия</h5>
        </div>
        <div class="card-body">
            <a href="{{ route('admin.clubs.create') }}" class="btn btn-primary me-2">
                <i class="bi bi-plus-circle me-1"></i> Добавить клуб
            </a>
        </div>
    </div>
</div>
@endsection