@extends('layouts.buyer.app')

@section('title', 'Reward Points')
@section('active', 'rewards')
@section('subtitle', 'Track your balance, earnings, and redemptions.')

@section('content')
@include('buyer.partials.rewards-content')
@endsection
