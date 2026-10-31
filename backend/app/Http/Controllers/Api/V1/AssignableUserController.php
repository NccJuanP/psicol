<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssignableUserController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $search = (string) ($request->validate(['search' => ['nullable', 'string', 'max:100']])['search'] ?? '');

        $users = User::query()
            ->assignable()
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name']);

        return response()->json(['data' => $users->map->only(['id', 'name'])->values()]);
    }
}
