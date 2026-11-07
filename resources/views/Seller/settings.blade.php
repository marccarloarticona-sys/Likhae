@extends('layouts.seller.app')
@section('title', __('settings.title'))
@section('active','settings')
@section('subtitle', __('settings.descriptions.seller'))
@push('head')@vite('resources/css/shared/workspace-settings.css')@endpush
@section('content')<x-shared.workspace.settings :config="$settingsConfig" :preferences="$preferences" />@endsection
