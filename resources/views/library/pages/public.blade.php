<div class="heading-row"><div><h1>{{ $title }}</h1><p class="subheading">{{ $library['owner'] }} · {{ $library['description'] }}</p></div>@unless($library['mine'])<button class="button secondary" data-action="affiliate" data-id="{{ $library['id'] }}">{{ $t('Richiedi affiliazione', 'Request connection') }}</button>@endunless</div>
@include('library.partials.stats')
<div class="library-layout">@include('library.partials.shelves')@include('library.partials.rail')</div>
