<?php

namespace App\Http\Controllers;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\AccountNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

// Super administrators add administrator accounts, change their level, and disable or
// re-enable them. Accounts are disabled rather than deleted, so their history stays readable.
// At least one active super administrator always remains, and nobody changes their own account.
class AdminAccountController extends ApiController
{
    public function __construct(private readonly AccountNotificationService $notifications) {}

    public function index(): JsonResponse
    {
        $me = $this->currentUser();
        $admins = User::query()->where('role', 'admin')->orderBy('full_name')->get()
            ->map(fn (User $admin): array => [
                'id' => (string) $admin->user_id,
                'fullName' => $admin->full_name,
                'username' => $admin->username,
                'email' => $admin->email,
                'level' => $admin->admin_level,
                'levelLabel' => $admin->adminLevelLabel(),
                'active' => $admin->account_status === 'approved',
                'isYou' => $admin->user_id === $me->user_id,
            ]);

        return response()->json(['success' => true, 'admins' => $admins->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'fullName' => ['required', 'string', 'min:2', 'max:100'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9_.-]+$/', Rule::unique('USERS', 'username')],
            'email' => ['required', 'email', 'max:120', Rule::unique('USERS', 'email')],
            'level' => ['required', Rule::in(User::ADMIN_LEVELS)],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ], [
            'username.unique' => 'That username is already taken.',
            'email.unique' => 'An account with that email address already exists.',
            'username.regex' => 'Usernames may use letters, numbers, dots, dashes, and underscores.',
            'password.confirmed' => 'The two passwords do not match.',
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }
        $data = $validator->validated();

        $admin = new User([
            'username' => trim($data['username']),
            'full_name' => trim($data['fullName']),
            'email' => trim($data['email']),
            'password_hash' => Hash::make($data['password']),
        ]);
        $admin->forceFill([
            'role' => 'admin',
            'admin_level' => $data['level'],
            'account_status' => 'approved',
            'email_verified_at' => now(),
            'email_notifications' => false,
        ])->save();
        $this->audit('create_admin', $admin, ['level' => $data['level']]);

        $this->notifications->send(
            $admin->email,
            'Your administrator account',
            "An administrator account ({$admin->adminLevelLabel()}) was created for you on the Barangay Reporting System, with the username {$admin->username}. Sign in at ".url('/').' with the temporary password your super administrator gives you, then change it in Account. Each sign-in also asks for a code sent to this email address.',
        );

        return response()->json(['success' => true, 'id' => (string) $admin->user_id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'level' => ['sometimes', Rule::in(User::ADMIN_LEVELS)],
            'active' => ['sometimes', 'boolean'],
        ]);
        if ($validator->fails() || $request->only(['level', 'active']) === []) {
            return $this->error('Choose a level or whether the account is active.', 422);
        }
        $data = $validator->validated();
        $me = $this->currentUser();

        return DB::transaction(function () use ($id, $data, $me): JsonResponse {
            $admin = User::query()->where('role', 'admin')->lockForUpdate()->find($id);
            if (! $admin) {
                return $this->error('Administrator not found.', 404);
            }
            if ($admin->user_id === $me->user_id) {
                return $this->error('You cannot change your own administrator account. Ask another super administrator.', 409);
            }

            $level = $data['level'] ?? $admin->admin_level;
            $status = array_key_exists('active', $data) ? ($data['active'] ? 'approved' : 'suspended') : $admin->account_status;
            $losesSuper = $admin->admin_level === 'super' && $admin->account_status === 'approved'
                && ($level !== 'super' || $status !== 'approved');
            if ($losesSuper) {
                $otherSupers = User::query()->where('role', 'admin')->where('admin_level', 'super')
                    ->where('account_status', 'approved')->whereKeyNot($admin->user_id)->lockForUpdate()->count();
                if ($otherSupers === 0) {
                    return $this->error('At least one active super administrator must remain.', 409);
                }
            }

            $admin->forceFill(['admin_level' => $level, 'account_status' => $status])->save();
            $this->audit('update_admin', $admin, ['level' => $level, 'active' => $status === 'approved']);

            return response()->json(['success' => true]);
        });
    }

    private function audit(string $action, User $admin, array $details): void
    {
        AdminAuditLog::create([
            'actor_user_id' => $this->currentUser()->user_id,
            'action' => $action,
            'details' => ['admin_user_id' => $admin->user_id] + $details,
        ]);
    }
}
