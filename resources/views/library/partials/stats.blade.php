<div class="stats-row">
    @foreach ([[$books->count(), $t('Libri nella libreria', 'Books in your library'), 'library'], [$books->where('progress', 100)->count(), $t('Storie già vissute', 'Stories already lived'), 'check'], [$books->whereBetween('progress', [1, 99])->count(), $t('Letture in corso', 'Currently reading'), 'book'], [$ranking->count(), $t('Autori da conoscere', 'Authors on your shelves'), 'users']] as [$number, $label, $symbol])
        <div class="stat"><span class="stat-symbol"><x-icon :name="$symbol"/></span><div><strong>{{ $number }}</strong><small>{{ $label }}</small></div></div>
    @endforeach
</div>
