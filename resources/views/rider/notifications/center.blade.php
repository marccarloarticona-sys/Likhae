@extends('layouts.rider.app')
@section('title', 'Notifications')
@section('subtitle', 'Review the latest updates for your account.')
@section('active', 'notifications')
@section('content')
    <x-shared.notifications.center-content :notifications="$notifications" :unread-count="$unreadCount" />
@endsection
