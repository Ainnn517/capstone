<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class AdminAuditLog extends Model
{
    protected $table = 'ADMIN_AUDIT_LOG';

    protected $primaryKey = 'audit_id';

    public $timestamps = false;

    protected $fillable = ['actor_user_id', 'action', 'details'];

    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Every entry made during a web request records where it came from. The IP is the
        // real client address passed on by the tunnel (see App\Support\ProxyTrust).
        static::creating(function (self $entry): void {
            if ((app()->runningInConsole() && ! app()->runningUnitTests()) || ! self::hasRequestColumns()) {
                return;
            }
            $request = request();
            $entry->ip_address ??= $request->ip();
            $entry->user_agent ??= mb_substr((string) $request->userAgent(), 0, 255) ?: null;
        });
    }

    private static ?bool $hasRequestColumns = null;

    // False until the 2026_11_16 migration has run, so recording never breaks a sign-in
    // or an admin action on a database that has not been migrated yet.
    private static function hasRequestColumns(): bool
    {
        return self::$hasRequestColumns ??= Schema::hasColumn('ADMIN_AUDIT_LOG', 'ip_address');
    }

    // Record a sign-in event. The username tried is stored only as a short keyed hash, so
    // repeated attempts on one account can be spotted without keeping what was typed.
    public static function recordAuth(string $action, ?int $userId, array $details = []): void
    {
        static::create([
            'actor_user_id' => $userId,
            'action' => $action,
            'details' => $details === [] ? (object) [] : $details,
        ]);
    }

    public static function usernameFingerprint(string $username): string
    {
        return substr(hash_hmac('sha256', mb_strtolower(trim($username)), (string) config('app.key')), 0, 16);
    }
}
