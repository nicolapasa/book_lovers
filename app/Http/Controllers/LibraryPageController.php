<?php

namespace App\Http\Controllers;

use App\Models\Library;
use App\Models\LibraryBook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LibraryPageController extends Controller
{
    public function __construct(private LibraryController $libraries, private AdminController $admin) {}

    public function index(Request $request): View
    {
        return view('welcome', ['screen' => view('library.shell', $this->context($request))->render()]);
    }

    public function screen(Request $request): JsonResponse
    {
        $context = $this->context($request);

        return response()->json(['html' => view('library.shell', $context)->render(), 'csrf' => csrf_token(), 'title' => $context['title'],
            'page' => $context['page'], 'library_id' => $context['library']['id'] ?? null]);
    }

    public function dialog(Request $request): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', 'in:auth,login,profile,import,search,manual,detail,share,new-library'], 'library' => ['nullable', 'integer'], 'entry' => ['nullable', 'integer']]);
        $context = $this->context($request, 'library');
        $context['kind'] = $data['kind'];
        $context['entry'] = null;
        if (isset($data['entry'])) {
            $entry = LibraryBook::query()->findOrFail($data['entry']);
            $context['library'] = $this->libraries->show($request, $entry->library)->getData(true);
            $context['entry'] = collect($context['library']['books'])->firstWhere('id', $entry->id);
        }
        if (! in_array($data['kind'], ['auth', 'login', 'detail'], true)) {
            abort_unless($request->user(), 401);
        }
        if (in_array($data['kind'], ['manual', 'import', 'search', 'share'], true)) {
            abort_unless($context['library']['mine'] ?? false, 403);
        }
        if ($data['kind'] === 'detail') {
            abort_unless($context['entry'], 404);
        }

        return response()->json(['html' => view('library.dialogs.'.$data['kind'], $context)->render()]);
    }

    /** @return array<string, mixed> */
    private function context(Request $request, ?string $forcedPage = null): array
    {
        $request->validate(['view' => ['nullable', 'in:library,explore,friends,reviews,stats,admin,public'], 'library' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:255'], 'filter' => ['nullable', 'in:all,reading,read,unread'], 'sort' => ['nullable', 'in:recent,title,rating'], 'layout' => ['nullable', 'in:grid,list']]);
        $state = $this->libraries->state($request)->getData(true);
        $page = $forcedPage ?? $request->query('view', 'library');
        $locale = app()->getLocale();
        $t = fn (string $it, string $en): string => $locale === 'en' ? $en : $it;
        $titles = ['library' => $t('La mia libreria', 'My library'), 'explore' => $t('Esplora le librerie', 'Explore libraries'),
            'friends' => $t('Amici e affiliazioni', 'Friends & connections'), 'reviews' => $t('Le nostre parole', 'Our words'),
            'stats' => $t('La tua vita tra i libri', 'Your life in books'), 'admin' => $t('Amministrazione', 'Administration'), 'public' => $t('Libreria condivisa', 'Shared library')];
        $library = collect($state['libraries'])->firstWhere('id', (int) $request->query('library')) ?? ($state['libraries'][0] ?? null);
        if ($page === 'public') {
            $library = $this->libraries->show($request, Library::query()->findOrFail($request->query('library')))->getData(true);
            $titles['public'] = $library['name'];
        }
        $books = collect($library['books'] ?? []);
        $ranking = $books->flatMap(fn (array $book): array => $book['authors'])->countBy()->sortDesc();
        $query = mb_strtolower($request->query('q', ''));
        $filter = $request->query('filter', 'all');
        $status = fn (array $book): string => $book['progress'] === 100 ? 'read' : ($book['progress'] > 0 ? 'reading' : 'unread');
        $filtered = $books->filter(fn (array $book): bool => ($filter === 'all' || $status($book) === $filter)
            && str_contains(mb_strtolower($book['title'].' '.$book['author'].' '.implode(' ', $book['identifiers'])), $query));
        $sort = $request->query('sort', 'recent');
        if ($sort === 'title') {
            $filtered = $filtered->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE);
        } elseif ($sort === 'rating') {
            $filtered = $filtered->sortByDesc('rating');
        }
        $initials = fn (?string $name): string => mb_strtoupper(collect(explode(' ', $name ?? 'Book Lovers'))->filter()->take(2)->map(fn (string $word): string => mb_substr($word, 0, 1))->join(''));

        return compact('state', 'page', 'locale', 't', 'library', 'books', 'ranking', 'filtered', 'filter', 'sort', 'status', 'initials') + [
            'title' => $titles[$page], 'search' => $request->query('q', ''), 'layout' => $request->query('layout', 'grid'),
            'community' => $page === 'explore' ? $this->libraries->explore($request)->getData(true) : [],
            'adminData' => $page === 'admin' ? $this->admin->index($request)->getData(true) : null,
        ];
    }
}
