<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ResidentController extends ApiController
{
    public function __construct(private readonly AccountNotificationService $accountNotifications) {}

    // List residents for administrator accounts.
    public function index(): JsonResponse
    {
        $residents = User::query()
            ->where('role', 'resident')
            ->orderByRaw("CASE account_status WHEN 'pending' THEN 0 WHEN 'rejected' THEN 1 ELSE 2 END")
            ->orderBy('full_name')
            ->orderBy('user_id')
            ->get()
            ->map(fn (User $resident): array => [
                'id' => (string) $resident->user_id,
                'username' => $resident->username,
                'fullName' => $resident->full_name,
                'email' => $resident->email,
                'accountStatus' => $resident->account_status,
                'registered' => date(DATE_ATOM, strtotime((string) $resident->created_at)),
            ]);

        return response()->json(['success' => true, 'residents' => $residents]);
    }

    // Approve or reject a resident account awaiting review.
    public function updateApproval(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'reason' => ['required_if:status,rejected', 'nullable', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $status = $validator->validated()['status'];
        $reason = trim((string) ($validator->validated()['reason'] ?? ''));
        if ($status === 'rejected' && $reason === '') {
            return $this->error('Enter a reason for rejecting this resident account.', 422);
        }
        $resident = User::query()
            ->where('role', 'resident')
            ->where('user_id', $id)
            ->first();

        if (! $resident) {
            return $this->error('Resident account not found.', 404);
        }

        if ($status === 'approved') {
            if ($resident->account_status === 'approved') {
                return $this->error('This resident account is already approved.', 409);
            }

            $resident->update(['account_status' => $status]);

            $emailStatus = $this->accountNotifications->send(
                $resident->email,
                'Your account has been approved',
                'Your Barangay Reporting System account has been approved. You can now sign in.',
            );

            return response()->json(['success' => true, 'accountStatus' => $status, 'emailStatus' => $emailStatus]);
        }

        if ($resident->account_status !== 'pending') {
            return $this->error('Only pending resident accounts can be rejected.', 409);
        }

        $resident->update(['account_status' => $status]);

        $emailStatus = $this->accountNotifications->send(
            $resident->email,
            'Your account was not approved',
            "Your Barangay Reporting System account was not approved. Reason: {$reason}",
        );

        return response()->json(['success' => true, 'accountStatus' => $status, 'emailStatus' => $emailStatus]);
    }
}
