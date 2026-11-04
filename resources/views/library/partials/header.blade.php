<header class="topbar">
    <button class="icon-button mobile-menu" data-action="menu" aria-expanded="false" aria-label="{{ $t('Apri menu', 'Open menu') }}"><x-icon name="menu"/></button>
    <div class="breadcrumb"><span>{{ $t('Il tuo spazio', 'Your space') }}</span><x-icon name="arrow"/><strong>{{ $title }}</strong></div>
    <div class="top-actions">
        <form class="global-search" data-form="filter"><x-icon name="search"/><input name="q" value="{{ $search }}" placeholder="{{ $t('Cerca un libro, un autore…', 'Search a book, an author…') }}" aria-label="{{ $t('Cerca nella libreria', 'Search your library') }}"></form>
        <div class="lang-switch">@foreach(['it', 'en'] as $lang)<button data-action="lang" data-lang="{{ $lang }}" class="{{ $locale === $lang ? 'active' : '' }}" aria-pressed="{{ $locale === $lang ? 'true' : 'false' }}">{{ strtoupper($lang) }}</button>@if($loop->first)<span>/</span>@endif @endforeach</div>
        <button class="icon-button" data-action="nav" data-view="friends" aria-label="{{ $t('Richieste di affiliazione', 'Connection requests') }}"><x-icon name="bell"/></button>
        <button class="avatar" data-action="dialog" data-kind="{{ $state['user'] ? 'profile' : 'auth' }}" aria-label="{{ $t('Profilo', 'Profile') }}">{{ $initials($state['user']['name'] ?? null) }}</button>
    </div>
</header>
