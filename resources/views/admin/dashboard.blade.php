@extends('admin.layout')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')
<div class="row">
    <div class="col-xl-4 col-md-6 mb-3">
        <div class="stats-card primary">
            <div class="stats-icon"><i class="fas fa-users"></i></div>
            <p>Tổng Users</p>
            <h3>{{ number_format($totalUsers) }}</h3>
        </div>
    </div>
    <div class="col-xl-4 col-md-6 mb-3">
        <div class="stats-card success">
            <div class="stats-icon"><i class="fas fa-crown"></i></div>
            <p>Premium Users</p>
            <h3>{{ number_format($premiumUsers) }}</h3>
        </div>
    </div>
    <div class="col-xl-4 col-md-6 mb-3">
        <div class="stats-card info">
            <div class="stats-icon"><i class="fas fa-user-plus"></i></div>
            <p>User Mới Hôm Nay</p>
            <h3>{{ number_format($newUsersToday) }}</h3>
        </div>
    </div>
</div>
@endsection
