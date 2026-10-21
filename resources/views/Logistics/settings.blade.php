@extends('layouts.logistics.app')
@section('title','System Settings')
@push('styles')@vite('resources/css/shared/workspace-settings.css')@endpush
@section('content')<x-shared.workspace.settings :config="$settingsConfig" :preferences="$preferences" />@endsection
