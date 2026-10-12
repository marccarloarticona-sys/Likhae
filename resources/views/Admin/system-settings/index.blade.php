@extends('layouts.admin')
@section('title','System Settings')
@section('active','system-settings')
@section('subtitle','Personalize operational alerts and your Admin workspace.')
@push('head')@vite('resources/css/shared/workspace-settings.css')@endpush
@section('content')<x-workspace-settings :config="$settingsConfig" :preferences="$preferences" />@endsection
