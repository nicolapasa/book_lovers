@props(['name' => 'book'])
@php
    $paths = [
        'book' => 'M3 4c4-1 6 0 9 2 3-2 5-3 9-2v15c-4-1-6 0-9 2-3-2-5-3-9-2ZM12 6v15',
        'library' => 'M4 3v18M8 3v18M12 4v17M16 4l5 16M2 21h20',
        'compass' => 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20ZM16 8l-3 5-5 3 3-5Z',
        'users' => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM20 21v-2a4 4 0 0 0-3-4M16 3a4 4 0 0 1 0 8',
        'review' => 'M21 3H3v15h4v4l5-4h9ZM7 8h10M7 12h7',
        'chart' => 'M3 3v18h18M7 16v-4M12 16V7M17 16V3',
        'shield' => 'M12 2l9 4v6c0 5-9 10-9 10S3 17 3 12V6ZM9 12l2 2 4-4',
        'arrow' => 'M4 12h16M15 7l5 5-5 5', 'plus' => 'M12 5v14M5 12h14',
        'search' => 'M20 20l-5-5M10 17a7 7 0 1 0 0-14 7 7 0 0 0 0 14Z',
        'bell' => 'M18 8a6 6 0 0 0-12 0c0 7-3 8-3 8h18s-3-1-3-8M10 21h4',
        'share' => 'M8 12l8-5M8 13l8 5M5 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM19 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM19 22a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z',
        'grid' => 'M3 3h7v7H3ZM14 3h7v7h-7ZM3 14h7v7H3ZM14 14h7v7h-7Z',
        'list' => 'M8 5h13M8 12h13M8 19h13M3 5h.1M3 12h.1M3 19h.1',
        'check' => 'M5 12l4 4L19 6', 'heart' => 'M20 5c-3-3-7-1-8 1-1-2-5-4-8-1-4 4 1 10 8 15 7-5 12-11 8-15Z',
        'close' => 'M6 6l12 12M6 18L18 6', 'menu' => 'M4 6h16M4 12h16M4 18h16',
    ];
@endphp
<svg {{ $attributes->class('icon') }} viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $paths[$name] ?? $paths['book'] }}"/></svg>
