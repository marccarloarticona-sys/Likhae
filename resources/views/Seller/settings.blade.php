@extends('layouts.seller.app')
@section('title','System Settings')
@section('active','settings')
@section('subtitle','Personalize Seller Center alerts and workspace behavior.')
@push('head')@vite('resources/css/shared/workspace-settings.css')@endpush
@section('content')<x-shared.workspace.settings :config="$settingsConfig" :preferences="$preferences" />@endsection
