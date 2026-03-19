<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>{{ config('app.name', 'Leads AI') }}</title>
    <link rel="icon" type="image/png" href="/images/leads-logo.png">
    <link rel="apple-touch-icon" href="/images/leads-logo.png">
    <meta property="og:image" content="/images/leads-logo.png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="antialiased">
    @inertia
</body>
</html>
