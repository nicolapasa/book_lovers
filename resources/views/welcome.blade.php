<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#254e40">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="app-url" content="{{ url('/') }}">
<meta name="description" content="Book Lovers — la tua libreria, condivisa.">
<title>Book Lovers — La tua libreria, condivisa.</title>
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
<link rel="stylesheet" href="{{ asset('css/book-lovers.css') }}">
<link rel="stylesheet" href="{{ asset('css/live-app.css') }}">
<script @empty($demo) type="module" @endempty src="{{ asset(!empty($demo) ? 'js/book-lovers.js' : 'js/library-app.js') }}" defer></script>
</head>
<body>
<a href="#main" class="skip-link">Skip to content / Vai al contenuto</a>
<div id="app">@isset($screen){!! $screen !!}@endisset</div>
<dialog id="modal" aria-labelledby="modal-title"></dialog>
<div id="toast" role="status" aria-live="polite"></div>
<noscript>Abilita JavaScript / Enable JavaScript.</noscript>
</body>
</html>
