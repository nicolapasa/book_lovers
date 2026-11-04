@php
    $cover = $book['cover'] ?? '';
    $safeCover = preg_match('~^https://(?:covers\.openlibrary\.org|books\.google\.com|books\.googleusercontent\.com)/~', $cover) || preg_match('~^/app/entries/\d+/cover$~', $cover);
@endphp
<img class="cover" src="{{ $safeCover ? $cover : '/images/book-placeholder.svg' }}" alt="{{ $book['title'] }}" loading="lazy">
