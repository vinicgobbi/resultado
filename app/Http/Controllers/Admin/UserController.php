<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /**
     * List admin users.
     */
    public function index(): Response
    {
        return Inertia::render('admin/users/index', [
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email', 'created_at']),
        ]);
    }

    /**
     * Create a new admin user. The password is hashed by the model cast.
     */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = User::create($request->validated());

        return back()->with('success', "Usuário {$user->email} criado.");
    }

    /**
     * Delete an admin user (users cannot delete themselves).
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($request->user()?->is($user)) {
            return back()->with('error', 'Você não pode excluir o seu próprio usuário.');
        }

        $user->delete();

        return back()->with('success', "Usuário {$user->email} excluído.");
    }
}
