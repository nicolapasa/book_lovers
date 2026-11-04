<aside class="sidebar" aria-label="{{ $t('Menu principale', 'Main menu') }}">
    <a class="brand" href="/"><span class="brand-mark"><x-icon name="book"/></span><span class="brand-name">book<span>lovers</span></span></a>
    <div class="nav-label">{{ $t('Il tuo spazio', 'Your space') }}</div>
    <nav>
        @foreach ([['library', 'library', $t('La mia libreria', 'My library')], ['explore', 'compass', $t('Esplora librerie', 'Explore libraries')], ['friends', 'users', $t('Amici e affiliazioni', 'Friends & connections')], ['reviews', 'review', $t('Recensioni', 'Reviews')], ['stats', 'chart', $t('Le mie statistiche', 'My statistics')]] as [$key, $symbol, $label])
            <button class="nav-button {{ $page === $key ? 'active' : '' }}" data-action="nav" data-view="{{ $key }}" @if($page === $key) aria-current="page" @endif><x-icon :name="$symbol"/>{{ $label }} @if($key === 'friends' && count($state['requests']))<span class="badge">{{ count($state['requests']) }}</span>@endif</button>
        @endforeach
    </nav>
    <div class="sidebar-bottom">
        <div class="sidebar-quote">“{{ $t('Un lettore vive mille vite prima di morire.', 'A reader lives a thousand lives before he dies.') }}”<small>GEORGE R. R. MARTIN</small></div>
        @if($state['user']['is_admin'] ?? false)<button class="nav-button" data-action="nav" data-view="admin"><x-icon name="shield"/>{{ $t('Amministrazione', 'Administration') }}</button>@endif
        <button class="profile" data-action="dialog" data-kind="{{ $state['user'] ? 'profile' : 'auth' }}"><span class="avatar">{{ $initials($state['user']['name'] ?? null) }}</span><span><strong>{{ $state['user']['name'] ?? $t('Il tuo spazio', 'Your space') }}</strong><small>{{ $state['user'] ? $t('Profilo personale', 'Personal profile') : $t('Accedi o registrati', 'Sign in or register') }}</small></span><x-icon name="arrow"/></button>
    </div>
</aside>
