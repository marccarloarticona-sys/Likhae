@extends('layouts.rider.app')
@section('title', __('settings.title'))
@section('subtitle', __('settings.descriptions.rider'))
@push('head')@vite('resources/css/shared/workspace-settings.css')@endpush
@section('content')<x-shared.workspace.settings :config="$settingsConfig" :preferences="$preferences" />@endsection
