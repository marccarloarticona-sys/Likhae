<?php

namespace App\Models;

use App\Models\Admin\AuditLog;
use App\Models\Admin\Notification;
use App\Models\Auth\RegistrationApplication;
use App\Models\Buyer\Address;
use App\Models\Buyer\BuyerVoucherClaim;
use App\Models\Buyer\Cart;
use App\Models\Buyer\Order;
use App\Models\Buyer\WishlistItem;
use App\Models\Communication\Conversation;
use App\Models\Communication\Message;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Rider\RiderProfile;
use App\Models\Seller\SellerProfile;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class User extends Authenticatable implements MustVerifyEmailContract
{
    public const TYPE_BUYER = 'BUYER';

    public const TYPE_SELLER = 'SELLER';

    public const TYPE_ADMIN = 'ADMIN';

    public const TYPE_LOGISTICS = 'LOGISTICS';

    public const TYPE_RIDER = 'RIDER';

    public const ACCOUNT_TYPES = [
        self::TYPE_BUYER,
        self::TYPE_SELLER,
        self::TYPE_ADMIN,
        self::TYPE_LOGISTICS,
        self::TYPE_RIDER,
    ];

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_SUSPENDED = 'SUSPENDED';

    public const STATUS_DEACTIVATED = 'DEACTIVATED';

    public const MAX_ACCOUNT_WARNINGS = 3;

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
        self::STATUS_DEACTIVATED,
    ];

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, MustVerifyEmailTrait;

    protected $fillable = [
        'account_type',
        'first_name',
        'middle_initial',
        'last_name',
        'name_extension',
        'sex',
        'email',
        'contact_number',
        'birthday',
        'email_verified_at',
        'password',
        'status',
        'suspended_until',
        'permanently_banned_at',
        'notification_preferences',
        'profile_photo_path',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'email_verified_at' => 'datetime',
            'api_token_expires_at' => 'datetime',
            'suspended_until' => 'datetime',
            'permanently_banned_at' => 'datetime',
            'notification_preferences' => 'array',
            'password' => 'hashed',
        ];
    }

    public function getNameAttribute(): string
    {
        return trim(collect([
            $this->first_name,
            $this->middle_initial,
            $this->last_name,
            $this->name_extension,
        ])->filter()->implode(' '));
    }

    public function isAccountType(string ...$types): bool
    {
        $normalized = collect($types)
            ->map(fn (string $type): string => strtoupper($type === 'courier' ? self::TYPE_RIDER : $type))
            ->all();

        return in_array(strtoupper((string) $this->account_type), $normalized, true);
    }

    public function isActive(): bool
    {
        $this->restoreExpiredSuspensionIfDue();

        return ! $this->isPermanentlyBanned()
            && strtoupper((string) $this->status) === self::STATUS_ACTIVE;
    }

    public function isSuspended(): bool
    {
        $this->restoreExpiredSuspensionIfDue();

        return ! $this->isPermanentlyBanned()
            && strtoupper((string) $this->status) === self::STATUS_SUSPENDED;
    }

    public function isPermanentlyBanned(): bool
    {
        return $this->permanently_banned_at !== null;
    }

    public static function accountEnforcementSchemaReady(): bool
    {
        return Schema::hasColumn('users', 'suspended_until')
            && Schema::hasColumn('users', 'permanently_banned_at');
    }

    public static function expireDueSuspensions(): int
    {
        if (! self::accountEnforcementSchemaReady()) {
            return 0;
        }

        $expired = 0;

        static::query()
            ->where('status', self::STATUS_SUSPENDED)
            ->whereNull('permanently_banned_at')
            ->whereNotNull('suspended_until')
            ->where('suspended_until', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($users) use (&$expired): void {
                foreach ($users as $user) {
                    $expired += $user->restoreExpiredSuspensionIfDue() ? 1 : 0;
                }
            });

        return $expired;
    }

    public function inactiveMessage(): string
    {
        if ($this->isPermanentlyBanned()) {
            return 'Your account has been permanently banned from LIKHAE. Please contact support if you believe this decision was made in error.';
        }

        if ($this->isSuspended()) {
            return $this->suspended_until
                ? 'Your account is suspended until '.$this->suspended_until->format('M d, Y h:i A').'. Please contact support for assistance.'
                : 'Your account has been suspended. Please contact support for assistance.';
        }

        $latestApplication = $this->registrationApplications()->latest('submitted_at')->first();

        if ($latestApplication?->status === RegistrationApplication::STATUS_REJECTED) {
            return 'Your registration application was rejected.'
                .($latestApplication->rejection_reason ? ' Reason: '.$latestApplication->rejection_reason : '');
        }

        return match (strtoupper((string) $this->status)) {
            self::STATUS_PENDING => 'Your account is pending approval.',
            self::STATUS_SUSPENDED => 'Your account has been suspended. Please contact support for assistance.',
            self::STATUS_DEACTIVATED => 'Your account has been deactivated. Please contact support for assistance.',
            default => 'Your account is not active. Please contact support for assistance.',
        };
    }

    private function restoreExpiredSuspensionIfDue(): bool
    {
        if (! $this->exists
            || $this->isPermanentlyBanned()
            || strtoupper((string) $this->status) !== self::STATUS_SUSPENDED
            || ! $this->suspended_until
            || $this->suspended_until->isFuture()) {
            return false;
        }

        $restored = DB::transaction(function (): bool {
            $lockedUser = static::query()->whereKey($this->getKey())->lockForUpdate()->first();

            if (! $lockedUser
                || $lockedUser->isPermanentlyBanned()
                || strtoupper((string) $lockedUser->status) !== self::STATUS_SUSPENDED
                || ! $lockedUser->suspended_until
                || $lockedUser->suspended_until->isFuture()) {
                return false;
            }

            $suspendedUntil = $lockedUser->suspended_until;
            $lockedUser->forceFill([
                'status' => self::STATUS_ACTIVE,
                'suspended_until' => null,
            ])->save();
            $lockedUser->sellerProfile()->where('status', self::STATUS_SUSPENDED)->update(['status' => self::STATUS_ACTIVE]);
            $lockedUser->logisticsCenter()->where('status', self::STATUS_SUSPENDED)->update(['status' => self::STATUS_ACTIVE]);

            AuditLog::create([
                'event' => 'ACCOUNT_SUSPENSION_EXPIRED',
                'auditable_type' => self::class,
                'auditable_id' => $lockedUser->id,
                'old_values' => [
                    'status' => self::STATUS_SUSPENDED,
                    'suspended_until' => $suspendedUntil->toISOString(),
                ],
                'new_values' => [
                    'status' => self::STATUS_ACTIVE,
                    'reason' => 'The temporary suspension period ended.',
                ],
                'created_at' => now(),
            ]);
            Notification::create([
                'user_id' => $lockedUser->id,
                'type' => 'ACCOUNT_REACTIVATED',
                'title' => 'Suspension period ended',
                'message' => 'Your LIKHAE account suspension ended and account access has been restored.',
                'reference_type' => self::class,
                'reference_id' => $lockedUser->id,
                'action_url' => null,
            ]);

            return true;
        });

        if ($restored) {
            $this->refresh();
        }

        return $restored;
    }

    public function workspaceRoute(): string
    {
        return match (strtoupper((string) $this->account_type)) {
            self::TYPE_ADMIN => 'admin.dashboard',
            self::TYPE_SELLER => 'seller.dashboard',
            self::TYPE_LOGISTICS => 'logistics.dashboard',
            self::TYPE_RIDER => 'rider.dashboard',
            self::TYPE_BUYER => 'buyer.home',
            default => 'home',
        };
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    public function registrationApplications(): HasMany
    {
        return $this->hasMany(RegistrationApplication::class);
    }

    public function sellerProfile(): HasOne
    {
        return $this->hasOne(SellerProfile::class);
    }

    /** Transitional alias for old code. */
    public function sellers(): HasMany
    {
        return $this->hasMany(SellerProfile::class);
    }

    public function logisticsCenter(): HasOne
    {
        return $this->hasOne(LogisticsCenter::class, 'owner_user_id');
    }

    /** Transitional alias for old code. */
    public function logisticsProvider(): HasOne
    {
        return $this->logisticsCenter();
    }

    public function riderProfile(): HasOne
    {
        return $this->hasOne(RiderProfile::class);
    }

    /** Transitional alias for old code. */
    public function rider(): HasOne
    {
        return $this->riderProfile();
    }

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class, 'buyer_user_id');
    }

    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class, 'buyer_user_id')->latestOfMany();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'buyer_user_id');
    }

    public function buyerVoucherClaims(): HasMany
    {
        return $this->hasMany(BuyerVoucherClaim::class, 'buyer_user_id');
    }

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_participants')
            ->withPivot(['id', 'joined_at', 'left_at', 'last_read_at'])
            ->withTimestamps();
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_user_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_user_id');
    }
}
