@include('library.partials.sidebar')
<div class="workspace">
    @include('library.partials.header')
    <main id="main" tabindex="-1" data-library="{{ $library['id'] ?? '' }}">
        @include('library.pages.'.$page)
        <footer class="footer"><span>© {{ date('Y') }} Book Lovers · {{ $t('Ogni libro, una connessione.', 'Every book, a connection.') }}</span><span>{{ $t('La tua libreria, condivisa.', 'Your library, shared.') }} <x-icon name="heart"/></span></footer>
    </main>
</div>
