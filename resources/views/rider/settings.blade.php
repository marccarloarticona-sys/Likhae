@extends('layouts.rider.app')
@section('title','System Settings')
@push('head')@vite('resources/css/shared/workspace-settings.css')@endpush
@section('content')<x-shared.workspace.settings :config="$settingsConfig" :preferences="$preferences" />@endsection
