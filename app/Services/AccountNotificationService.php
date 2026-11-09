<?php

namespace App\Services;

use App\Mail\AccountUpdateMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class AccountNotificationService
{
    // Send account updates only when a real delivery transport is configured.
    public function send(mixed $candidateEmail, string $subject, string $message): string
    {
        $email = $this->validEmail($candidateEmail);
        if ($email === null) {
            return 'not_sent';
        }

        $mailer = config('mail.default');
        if (in_array($mailer, ['log', 'array'], true)) {
            return 'not_configured';
        }
        if ($mailer === 'smtp') {
            $smtp = config('mail.mailers.smtp');
            $sender = config('mail.from.address');
            if (! filled($smtp['host'] ?? null)
                || ! filled($smtp['username'] ?? null)
                || ! filled($smtp['password'] ?? null)
                || ! is_string($sender)
                || ! filter_var($sender, FILTER_VALIDATE_EMAIL)
                || str_ends_with(strtolower($sender), '@example.com')) {
                return 'not_configured';
            }
        }

        try {
            Mail::mailer($mailer)->to($email)->send(new AccountUpdateMail($subject, $message));
        } catch (TransportExceptionInterface $exception) {
            Log::error('Account update email delivery failed.', [
                'mailer' => $mailer,
                'exception' => $exception::class,
            ]);

            return 'failed';
        }

        return 'sent';
    }

    private function validEmail(mixed $candidateEmail): ?string
    {
        if (! is_string($candidateEmail)) {
            return null;
        }

        $email = trim($candidateEmail);
        if ($email === '' || mb_strlen($email) > 120
            || ! Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:120']])->passes()) {
            return null;
        }

        return $email;
    }
}
