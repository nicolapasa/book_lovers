<article class="book-card">
    <button class="book-cover-button" data-action="dialog" data-kind="detail" data-entry="{{ $book['id'] }}" aria-label="{{ $t('Apri', 'Open').' '.$book['title'] }}">
        @include('library.components.cover')
        @if($book['progress'])<span class="book-tag {{ $status($book) }}">@if($book['progress'] === 100)<x-icon name="check"/>@endif{{ $book['progress'] === 100 ? $t('Letto', 'Read') : $t('In lettura', 'Reading') }}</span>@endif
    </button>
    <button class="book-title" data-action="dialog" data-kind="detail" data-entry="{{ $book['id'] }}">{{ $book['title'] }}</button>
    <p class="book-author">{{ $book['author'] ?: $t('Autore non disponibile', 'Author unavailable') }}</p>
    <div class="book-meta">@if($book['rating'])@include('library.components.stars', ['rating' => $book['rating']])@else<span>{{ $t('Da scoprire', 'To discover') }}</span>@endif<span class="genre">{{ $book['genres'][0][$locale] ?? '—' }}</span></div>
</article>
