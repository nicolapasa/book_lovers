@if(count($results))
<form data-form="select-volumes">
    <p class="hint">{{ $t('Seleziona i libri da importare nella libreria.', 'Select the books to import into your library.') }}</p>
@endif
@forelse($results as $result)
    <article class="search-result"><div><h3>{{ $result['title'] }}</h3><p>{{ $result['author'] }}</p><p class="hint">{{ $result['publisher'] }} · {{ $result['date'] }} · {{ $result['language'] }}</p><p class="hint">{{ implode(' · ', $result['identifiers']) }}</p><label><input type="checkbox" name="volume_ids[]" value="{{ $result['id'] }}"> {{ $t('Seleziona', 'Select') }} {{ $result['title'] }}</label><p class="hint" data-import-status role="status"></p></div><button type="button" class="button primary small" data-action="select-volume" data-id="{{ $result['id'] }}">{{ $t('Scegli questa edizione', 'Choose this edition') }}</button></article>
@empty<p class="notice">{{ $t('Nessun risultato. Puoi inserire il libro manualmente.', 'No results. You can add the book manually.') }}</p>@endforelse
@if(count($results))
    <p class="form-error" role="alert"></p>
    <div class="modal-actions"><button class="button primary" type="submit">{{ $t('Importa i libri selezionati', 'Import selected books') }}</button></div>
</form>
@endif
