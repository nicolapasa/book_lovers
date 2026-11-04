@php
    $cover = $book['cover'] ?? '';
    $safeCover = preg_match('~^https://(?:covers\.openlibrary\.org|books\.google\.com|books\.googleusercontent\.com)/~', $cover) || $cover === url('/app/entries/'.$book['id'].'/cover');
@endphp
<img class="cover" src="{{ $safeCover ? $cover : asset('images/book-placeholder.svg') }}" alt="{{ $book['title'] }}" loading="lazy">
