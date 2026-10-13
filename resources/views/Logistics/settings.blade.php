@extends('Logistics.app')
@section('title','System Settings')
@push('styles')@vite('resources/css/shared/workspace-settings.css')@endpush
@section('content')<x-workspace-settings :config="$settingsConfig" :preferences="$preferences" />@endsection
