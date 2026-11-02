<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Core\Auth;
use Core\Log;
use Core\Request;
use Core\Response;

/**
 * Admin area (routes protected by auth + role:admin).
 */
final class UserController extends Controller
{
    private const ROLES = ['user', 'editor', 'admin'];

    public function index(Request $request): Response
    {
        $search = trim($request->string('q'));

        $users = User::query()
            ->when($search !== '', fn ($q) => $q->whereLike(['name', 'email'], $search))
            ->orderBy('id', 'DESC')
            ->paginate(15);

        return $this->view('admin.users', ['users' => $users, 'search' => $search, 'roles' => self::ROLES]);
    }

    public function updateRole(Request $request, User $user): Response
    {
        $data = $request->validate(['role' => 'required|in:' . implode(',', self::ROLES)]);

        $this->authorize((int) $user->id !== (int) Auth::id(), 'You cannot change your own role.');

        $user->forceFill(['role' => $data['role']])->save();
        Log::security('role_changed', ['target_user' => $user->id, 'role' => $data['role']]);

        return $this->back()->with('success', "{$user->name} is now {$data['role']}.");
    }
}
