<?php

namespace App\Http\Controllers;

use App\Models\Library;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->is_admin, 403);

        return response()->json([
            'users' => User::query()->withCount('libraries')->orderByDesc('id')->paginate(50, ['id', 'name', 'email', 'is_admin', 'suspended_at']),
            'libraries' => Library::query()->with('owner:id,name')->withCount('libraryBooks')->orderByDesc('id')->paginate(50, ['*'], 'libraries_page'),
        ]);
    }

    public function user(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->is_admin, 403);
        abort_if($request->user()->id === $user->id, 422);
        $data = $request->validate(['suspended' => ['required', 'boolean']]);
        $user->suspended_at = $data['suspended'] ? now() : null;
        $user->save();

        return response()->json(['ok' => true]);
    }

    public function library(Request $request, Library $library): JsonResponse
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate(['hidden' => ['required', 'boolean']]);
        $library->hidden_at = $data['hidden'] ? now() : null;
        $library->save();

        return response()->json(['ok' => true]);
    }
}
