<?php

namespace App\Services\Admin;

use App\Models\Admin\AuditLog;
use App\Models\Admin\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class AdminUserSessionManager
{
    private const SUPPORTED_DRIVERS = ['file', 'database'];

    public function available(): bool
    {
        return in_array((string) config('session.driver'), self::SUPPORTED_DRIVERS, true)
            && $this->sessionTableReady();
    }

    /** @param iterable<User> $users
     *  @return array<int, array{ip_address:?string,browser:string,session_count:int,last_activity:?int}>
     */
    public function summaries(iterable $users): array
    {
        $userIds = collect($users)->pluck('id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $summaries = [];
        foreach ($userIds as $userId) {
            $summaries[$userId] = [
                'ip_address' => null,
                'browser' => 'Not captured',
                'session_count' => 0,
                'last_activity' => null,
            ];
        }

        if (! $this->available() || $userIds === []) {
            return $summaries;
        }

        foreach ($this->activeSessions($userIds) as $userId => $sessions) {
            usort($sessions, fn (array $left, array $right): int => $right['last_activity'] <=> $left['last_activity']);
            $latest = $sessions[0] ?? null;
            if ($latest === null) {
                continue;
            }

            $summaries[$userId] = [
                'ip_address' => $latest['ip_address'],
                'browser' => $this->browserName($latest['user_agent']),
                'session_count' => count($sessions),
                'last_activity' => $latest['last_activity'],
            ];
        }

        return $summaries;
    }

    /** @param list<int> $userIds
     *  @return array<int, list<array{id:string,ip_address:?string,user_agent:?string,last_activity:int}>>
     */
    public function activeSessions(array $userIds): array
    {
        $userIds = collect($userIds)->map(fn ($id): int => (int) $id)->unique()->values()->all();
        if (! $this->available() || $userIds === []) {
            return [];
        }

        $cutoff = now()->subMinutes(max(1, (int) config('session.lifetime', 120)))->timestamp;
        $driver = (string) config('session.driver');
        $sessions = [];
        $knownIds = [];

        $rows = DB::table((string) config('session.table', 'sessions'))
            ->whereIn('user_id', $userIds)
            ->where('last_activity', '>=', $cutoff)
            ->get(['id', 'user_id', 'ip_address', 'user_agent', 'last_activity']);

        foreach ($rows as $row) {
            $sessionId = (string) $row->id;
            if (! $this->validSessionId($sessionId)) {
                continue;
            }

            $lastActivity = (int) $row->last_activity;
            if ($driver === 'file') {
                $path = $this->filePath($sessionId);
                if ($path === null || ! is_file($path)) {
                    continue;
                }
                $lastActivity = (int) (@filemtime($path) ?: $lastActivity);
                if ($lastActivity < $cutoff) {
                    continue;
                }
            }

            $userId = (int) $row->user_id;
            $sessions[$userId][$sessionId] = [
                'id' => $sessionId,
                'ip_address' => $row->ip_address !== null ? (string) $row->ip_address : null,
                'user_agent' => $row->user_agent !== null ? (string) $row->user_agent : null,
                'last_activity' => $lastActivity,
            ];
            $knownIds[$sessionId] = true;
        }

        if ($driver === 'file') {
            foreach ($this->recentSessionFiles($cutoff) as [$sessionId, $path, $lastActivity]) {
                if (isset($knownIds[$sessionId])) {
                    continue;
                }

                $userId = $this->userIdFromSessionFile($path);
                if ($userId === null || ! in_array($userId, $userIds, true)) {
                    continue;
                }

                $sessions[$userId][$sessionId] = [
                    'id' => $sessionId,
                    'ip_address' => null,
                    'user_agent' => null,
                    'last_activity' => $lastActivity,
                ];
            }
        }

        return array_map(fn (array $items): array => array_values($items), $sessions);
    }

    /**
     * Revoke all browser sessions and persistent API/remember-me credentials for the given users.
     * Returns the number of active browser sessions revoked.
     *
     * @param list<User> $users
     */
    public function revoke(Request $request, array $users): int
    {
        if (! $this->available()) {
            throw new \RuntimeException('Active session management is unavailable for the configured session driver or database schema.');
        }

        $users = collect($users)->unique('id')->values()->all();
        $sessions = $this->activeSessions(collect($users)->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $sessionTable = (string) config('session.table', 'sessions');
        $allSessionIds = [];

        foreach ($users as $user) {
            foreach ($sessions[$user->id] ?? [] as $session) {
                $allSessionIds[$session['id']] = (int) $user->id;
            }
        }

        $sessionIds = array_keys($allSessionIds);
        $sessionLifetime = max(1, (int) config('session.lifetime', 120)) + 5;
        $sessionCounts = [];
        foreach ($users as $user) {
            $sessionCounts[$user->id] = count($sessions[$user->id] ?? []);
        }

        $markedSessionIds = [];
        foreach ($sessionIds as $sessionId) {
            try {
                Cache::put($this->revocationCacheKey($sessionId), $allSessionIds[$sessionId], now()->addMinutes($sessionLifetime));
                $markedSessionIds[] = $sessionId;
            } catch (Throwable $exception) {
                foreach ($markedSessionIds as $markedSessionId) {
                    Cache::forget($this->revocationCacheKey($markedSessionId));
                }
                throw $exception;
            }
        }

        try {
            DB::transaction(function () use ($request, $users, $sessionIds, $sessionCounts, $sessionTable): void {
                foreach ($users as $user) {
                    $changes = ['remember_token' => null];
                    if (Schema::hasColumn('users', 'api_token_hash')) {
                        $changes['api_token_hash'] = null;
                    }
                    if (Schema::hasColumn('users', 'api_token_expires_at')) {
                        $changes['api_token_expires_at'] = null;
                    }
                    $user->forceFill($changes)->save();

                    $sessionCount = $sessionCounts[$user->id] ?? 0;

                    AuditLog::create([
                        'actor_user_id' => $request->user()->id,
                        'event' => 'USER_SESSIONS_REVOKED',
                        'auditable_type' => User::class,
                        'auditable_id' => $user->id,
                        'old_values' => ['active_sessions' => $sessionCount],
                        'new_values' => [
                            'action' => 'ADMIN_FORCED_SIGN_OUT',
                            'active_sessions_revoked' => $sessionCount,
                        ],
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'created_at' => now(),
                    ]);

                    Notification::create([
                        'user_id' => $user->id,
                        'type' => 'SESSION_SUSPENDED',
                        'title' => 'Session suspended',
                        'message' => 'Your active sessions were suspended by an administrator. Please sign in again to continue using your account.',
                        'reference_type' => User::class,
                        'reference_id' => $user->id,
                        'action_url' => null,
                    ]);
                }

                if ($sessionIds !== []) {
                    DB::table($sessionTable)->whereIn('id', $sessionIds)->delete();
                }
            });
        } catch (Throwable $exception) {
            foreach ($markedSessionIds as $markedSessionId) {
                Cache::forget($this->revocationCacheKey($markedSessionId));
            }
            throw $exception;
        }

        foreach ($sessionIds as $sessionId) {
            $path = $this->filePath($sessionId);
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
        }

        return count($sessionIds);
    }

    public function isRevoked(string $sessionId): bool
    {
        return $this->validSessionId($sessionId)
            && Cache::has($this->revocationCacheKey($sessionId));
    }

    public function revokedUserId(string $sessionId): ?int
    {
        if (! $this->validSessionId($sessionId)) {
            return null;
        }

        $userId = Cache::get($this->revocationCacheKey($sessionId));

        return is_numeric($userId) ? (int) $userId : null;
    }

    /** Save IP/browser metadata after Laravel has persisted the file-backed session. */
    public function recordFileSession(string $sessionId, int $userId, ?string $ipAddress, ?string $userAgent): void
    {
        if (config('session.driver') !== 'file' || ! $this->sessionTableReady() || ! $this->validSessionId($sessionId)) {
            return;
        }

        $path = $this->filePath($sessionId);
        if ($path === null || ! is_file($path)) {
            return;
        }

        DB::table((string) config('session.table', 'sessions'))->updateOrInsert(
            ['id' => $sessionId],
            [
                'user_id' => $userId,
                'ip_address' => $ipAddress !== null ? Str::limit($ipAddress, 45, '') : null,
                'user_agent' => $userAgent !== null ? Str::limit($userAgent, 4000, '') : null,
                'payload' => '',
                'last_activity' => (int) (@filemtime($path) ?: time()),
            ],
        );
    }

    public function browserName(?string $userAgent): string
    {
        if (blank($userAgent)) {
            return 'Not captured';
        }

        $agent = strtolower($userAgent);
        if (preg_match('/\bedg(?:e|a|ios)?\//', $agent)) {
            return 'Microsoft Edge';
        }
        foreach ([
            'opr/' => 'Opera',
            'opt/' => 'Opera',
            'samsungbrowser/' => 'Samsung Internet',
            'firefox/' => 'Firefox',
            'crios/' => 'Chrome',
            'chrome/' => 'Chrome',
            'fxios/' => 'Firefox',
            'safari/' => 'Safari',
            'msie ' => 'Internet Explorer',
            'trident/' => 'Internet Explorer',
        ] as $needle => $name) {
            if (str_contains($agent, $needle)) {
                return $name;
            }
        }

        return 'Other browser';
    }

    private function sessionTableReady(): bool
    {
        $table = (string) config('session.table', 'sessions');

        if (! Schema::hasTable($table)) {
            return false;
        }

        foreach (['id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity'] as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    /** @return iterable<array{0:string,1:string,2:int}> */
    private function recentSessionFiles(int $cutoff): iterable
    {
        $directory = (string) config('session.files');
        if (! is_dir($directory)) {
            return [];
        }

        $files = @scandir($directory);
        if (! is_array($files)) {
            return [];
        }

        $recent = [];
        foreach ($files as $file) {
            if (! $this->validSessionId($file)) {
                continue;
            }
            $path = $this->filePath($file);
            if ($path === null || ! is_file($path)) {
                continue;
            }
            $lastActivity = (int) (@filemtime($path) ?: 0);
            if ($lastActivity >= $cutoff) {
                $recent[] = [$file, $path, $lastActivity];
            }
        }

        return $recent;
    }

    private function userIdFromSessionFile(string $path): ?int
    {
        $payload = @file_get_contents($path);
        if (! is_string($payload) || $payload === '') {
            return null;
        }

        $attributes = $this->unserializePayload($payload);
        if ($attributes === null && (bool) config('session.encrypt')) {
            try {
                $attributes = $this->unserializePayload(Crypt::decryptString($payload));
            } catch (Throwable) {
                return null;
            }
        }
        if (! is_array($attributes)) {
            return null;
        }

        foreach ($this->authSessionKeys() as $key) {
            $userId = $attributes[$key] ?? null;
            if ((is_int($userId) || (is_string($userId) && ctype_digit($userId))) && (int) $userId > 0) {
                return (int) $userId;
            }
        }

        return null;
    }

    private function unserializePayload(string $payload): ?array
    {
        try {
            $attributes = @unserialize($payload, ['allowed_classes' => false]);
        } catch (Throwable) {
            return null;
        }

        return is_array($attributes) ? $attributes : null;
    }

    /** @return list<string> */
    private function authSessionKeys(): array
    {
        $keys = [];
        foreach ((array) config('auth.guards', []) as $name => $guard) {
            if (($guard['driver'] ?? null) !== 'session') {
                continue;
            }
            try {
                $keys[] = Auth::guard((string) $name)->getName();
            } catch (Throwable) {
                // Ignore guards that cannot be constructed in this request context.
            }
        }

        return array_values(array_unique($keys));
    }

    private function filePath(string $sessionId): ?string
    {
        if (! $this->validSessionId($sessionId)) {
            return null;
        }

        return rtrim((string) config('session.files'), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$sessionId;
    }

    private function validSessionId(string $sessionId): bool
    {
        return $sessionId !== '' && strlen($sessionId) <= 128 && ctype_alnum($sessionId);
    }

    private function revocationCacheKey(string $sessionId): string
    {
        return 'admin:revoked-session:'.hash('sha256', $sessionId);
    }

}
