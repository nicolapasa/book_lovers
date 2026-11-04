@include('library.dialogs.header', ['dialogTitle' => $state['user']['name'], 'dialogSubtitle' => $state['user']['email']])
<p class="hint">{{ $t('I tuoi libri sono salvati nel tuo account.', 'Your books are saved in your account.') }}</p><div class="modal-actions"><button class="button secondary" data-action="logout">{{ $t('Esci', 'Sign out') }}</button></div>
