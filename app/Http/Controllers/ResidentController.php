<?php

namespace App\Http\Controllers;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\AccountNotificationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ResidentController extends ApiController
{
    public function __construct(private readonly AccountNotificationService $accountNotifications) {}

    // List residents for administrator accounts.
    // Viewer-level administrators only read reports and statistics, so they see a resident's
    // name and area but not the email address, house details, or mobile number (RA 10173:
    // personal data limited to what each role needs). Staff and super admins see everything
    // they need to check residency and approve accounts.
    public function index(): JsonResponse
    {
        $fullDetails = $this->currentUser()->hasAnyRole(['staff']);
        $residents = User::query()
            ->with('addressLocation')
            ->where('role', 'resident')
            ->withCount('reports')
            ->orderByRaw("CASE account_status WHEN 'pending' THEN 0 WHEN 'rejected' THEN 1 WHEN 'suspended' THEN 2 ELSE 3 END")
            ->orderBy('full_name')
            ->orderBy('user_id')
            ->get()
            ->map(fn (User $resident): array => [
                'id' => (string) $resident->user_id,
                'username' => $resident->username,
                'fullName' => $resident->full_name,
                'email' => $fullDetails ? $resident->email : $this->maskEmail((string) $resident->email),
                'address' => $fullDetails ? $resident->addressLine() : $resident->addressLocation?->area_name,
                'mobile' => $fullDetails ? $resident->contact_number : null,
                'accountStatus' => $resident->account_status,
                // A fake or mistyped address can never be verified, so approval waits for it.
                'emailVerified' => ! config('auth.require_email_verification') || $resident->hasVerifiedEmail(),
                'registered' => Carbon::parse($resident->created_at, config('app.timezone'))->toIso8601String(),
                'reportCount' => $resident->reports_count,
            ]);

        return response()->json(['success' => true, 'residents' => $residents]);
    }

    private function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 1).'***@'.$domain;
    }

    // Approve, reject, or suspend a resident account.
    //   pending   -> approved | rejected
    //   rejected  -> approved
    //   approved  -> suspended (signs the resident out on their next request)
    //   suspended -> approved
    public function updateApproval(Request $request, int $id): JsonResponse
    {
        $actor = $this->currentUser();
        $validator = Validator::make($request->all(), [
            'status' => ['required', Rule::in(['approved', 'rejected', 'suspended'])],
            'reason' => ['required_if:status,rejected,suspended', 'nullable', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $status = $validator->validated()['status'];
        $reason = trim((string) ($validator->validated()['reason'] ?? ''));
        if ($status !== 'approved' && $reason === '') {
            return $this->error('Enter a reason for this account decision.', 422);
        }

        $allowedFrom = [
            'approved' => ['pending', 'rejected', 'suspended'],
            'rejected' => ['pending'],
            'suspended' => ['approved'],
        ];

        $result = DB::transaction(function () use ($id, $status, $reason, $actor, $allowedFrom): User|JsonResponse {
            // Lock the row so two administrators cannot apply the same decision twice.
            $resident = User::query()
                ->where('role', 'resident')
                ->where('user_id', $id)
                ->lockForUpdate()
                ->first();
            if (! $resident) {
                return $this->error('Resident account not found.', 404);
            }
            if (! in_array($resident->account_status, $allowedFrom[$status], true)) {
                return $this->error(match ($status) {
                    'approved' => 'This resident account is already approved.',
                    'rejected' => 'Only pending resident accounts can be rejected.',
                    'suspended' => 'Only approved resident accounts can be suspended.',
                }, 409);
            }

            if ($status === 'approved' && config('auth.require_email_verification') && ! $resident->hasVerifiedEmail()) {
                return $this->error('This resident has not verified their email address yet. Approve the account after they open the link in their verification email, or reject it.', 409);
            }

            $previousStatus = $resident->account_status;
            $resident->forceFill(['account_status' => $status])->save();
            if ($status !== 'approved') {
                // Rejected or suspended: any outstanding password reset link stops working.
                DB::table('PASSWORD_RESET_TOKENS')->where('email', $resident->email)->delete();
            }
            $details = ['resident_user_id' => $resident->user_id, 'previous_status' => $previousStatus];
            if ($reason !== '') {
                $details['reason'] = $reason;
            }
            AdminAuditLog::create([
                'actor_user_id' => $actor->user_id,
                'action' => match ($status) {
                    'approved' => $previousStatus === 'suspended' ? 'reactivate_resident' : 'approve_resident',
                    'rejected' => 'reject_resident',
                    'suspended' => 'suspend_resident',
                },
                'details' => $details,
            ]);

            return $resident;
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        [$subject, $message] = match ($status) {
            'approved' => [
                'Your account has been approved',
                'Your Barangay Reporting System account has been approved. You can now sign in.',
            ],
            'rejected' => [
                'Your account was not approved',
                "Your Barangay Reporting System account was not approved. Reason: {$reason}",
            ],
            'suspended' => [
                'Your account has been suspended',
                "Your Barangay Reporting System account has been suspended. Reason: {$reason}",
            ],
        };
        $emailStatus = $this->accountNotifications->send($result->email, $subject, $message);

        return response()->json(['success' => true, 'accountStatus' => $status, 'emailStatus' => $emailStatus]);
    }

    // Permanently remove a rejected resident account (for example, a non-resident or a
    // duplicate sign-up). Only rejected accounts qualify, so approved residents and their
    // report history can never be deleted from here.
    public function destroy(int $id): JsonResponse
    {
        $actor = $this->currentUser();

        return DB::transaction(function () use ($id, $actor): JsonResponse {
            $resident = User::query()
                ->where('role', 'resident')
                ->where('user_id', $id)
                ->lockForUpdate()
                ->first();
            if (! $resident) {
                return $this->error('Resident account not found.', 404);
            }
            if ($resident->account_status !== 'rejected') {
                return $this->error('Only rejected resident accounts can be deleted.', 409);
            }
            if ($resident->reports()->exists()) {
                return $this->error('This account has submitted reports and cannot be deleted.', 409);
            }

            // Notifications are removed by the database (ON DELETE CASCADE).
            DB::table('PASSWORD_RESET_TOKENS')->where('email', $resident->email)->delete();
            AdminAuditLog::create([
                'actor_user_id' => $actor->user_id,
                'action' => 'delete_rejected_resident',
                'details' => ['resident_user_id' => $resident->user_id, 'username' => $resident->username],
            ]);
            $resident->delete();

            return response()->json(['success' => true]);
        });
    }
}
