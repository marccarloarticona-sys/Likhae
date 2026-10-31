<?php

namespace App\Services\Fulfillment;

use App\Models\Logistics\TransferManifest;
use App\Models\Rider\InterhubAttendanceEvent;
use App\Models\Rider\InterhubAttendanceSession;
use App\Models\Rider\InterhubDriverRate;
use App\Models\Rider\InterhubPayrollBatch;
use App\Models\Rider\InterhubPayrollItem;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use App\Support\RiderVehicleType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class InterhubTimekeepingService
{
    public function ready(): bool
    {
        try {
            return Schema::hasTable('interhub_attendance_sessions')
                && Schema::hasTable('interhub_driver_rates')
                && Schema::hasTable('interhub_attendance_events')
                && Schema::hasTable('interhub_attendance_qr_tokens')
                && Schema::hasTable('interhub_attendance_qr_uses')
                && Schema::hasTable('interhub_payroll_batches')
                && Schema::hasTable('interhub_payroll_items');
        } catch (Throwable) {
            return false;
        }
    }

    public function effectiveRate(int $driverId, string $regionType, Carbon|string|null $date = null): ?InterhubDriverRate
    {
        $date = $date instanceof Carbon ? $date->toDateString() : ($date ?: now()->toDateString());

        return InterhubDriverRate::query()->where('rider_profile_id', $driverId)
            ->where('region_type', $regionType)->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
    }

    public function openSession(int $driverId): ?InterhubAttendanceSession
    {
        return InterhubAttendanceSession::query()->where('rider_profile_id', $driverId)->whereNull('clock_out_at')
            ->with(['manifest', 'logisticsCenter'])->latest('clock_in_at')->first();
    }

    public function clock(User $actor, int $driverId, int $centerId, string $source): InterhubAttendanceSession
    {
        $this->assertReady();

        return DB::transaction(function () use ($actor, $driverId, $centerId, $source): InterhubAttendanceSession {
            $driver = RiderProfile::query()->whereKey($driverId)->lockForUpdate()->with('user')->firstOrFail();
            if ((int) $driver->logistics_center_id !== $centerId || ! RiderVehicleType::isInterLogistics($driver->vehicle_type)) {
                throw new RuntimeException('This Interhub driver is not assigned to this Logistics center.');
            }

            $open = InterhubAttendanceSession::query()->where('rider_profile_id', $driverId)->whereNull('clock_out_at')->lockForUpdate()->first();
            if (! in_array($source, ['LOGISTICS_QR', 'DRIVER_QR'], true)) {
                throw new RuntimeException('The scan source is invalid.');
            }
            if (! $open) {
                if (! in_array($driver->status, ['ACTIVE', 'WARNED'], true) || $driver->user?->status !== User::STATUS_ACTIVE) {
                    throw new RuntimeException('Only an active Interhub driver can start a shift.');
                }
                $region = $this->regionForDriver($driver);
                $rate = $this->effectiveRate($driverId, $region);
                if (! $rate) {
                    throw new RuntimeException('Set an effective '.$region.' daily rate for this driver before recording Time In.');
                }
                $manifestId = $this->currentManifestId($driverId, $centerId);
                $session = InterhubAttendanceSession::query()->create([
                    'rider_profile_id' => $driverId, 'active_driver_id' => $driverId, 'logistics_center_id' => $centerId, 'manifest_id' => $manifestId,
                    'rate_id' => $rate->id, 'region_type' => $region, 'daily_rate_snapshot' => $rate->daily_rate,
                    'clock_in_at' => now(), 'clock_in_by_user_id' => $actor->id, 'clock_in_source' => $source,
                ]);
                $this->event($session, $actor, 'TIME_IN', null, $session->only(['manifest_id', 'region_type', 'daily_rate_snapshot', 'clock_in_at', 'clock_in_source']));

                return $session->refresh();
            }

            $clockOut = now();
            if ($clockOut->lessThanOrEqualTo($open->clock_in_at)) {
                throw new RuntimeException('Time Out must be after Time In.');
            }
            $minutes = intdiv($open->clock_in_at->diffInSeconds($clockOut), 60);
            $pay = round(((float) $open->daily_rate_snapshot * $minutes) / 480, 2, PHP_ROUND_HALF_UP);
            $open->update([
                'active_driver_id' => null, 'clock_out_at' => $clockOut, 'worked_minutes' => $minutes, 'gross_pay' => $pay,
                'overtime_review' => $minutes > 480, 'clock_out_by_user_id' => $actor->id, 'clock_out_source' => $source,
            ]);
            $this->event($open, $actor, 'TIME_OUT', null, $open->fresh()->only(['clock_out_at', 'worked_minutes', 'gross_pay', 'overtime_review', 'clock_out_source']));

            return $open->refresh();
        }, 3);
    }

    public function correct(InterhubAttendanceSession $session, User $actor, Carbon $clockIn, ?Carbon $clockOut, string $reason): InterhubAttendanceSession
    {
        $this->assertReady();

        return DB::transaction(function () use ($session, $actor, $clockIn, $clockOut, $reason): InterhubAttendanceSession {
            $locked = InterhubAttendanceSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($clockOut && $clockOut->lessThanOrEqualTo($clockIn)) {
                throw new RuntimeException('Corrected Time Out must be after Time In.');
            }
            $old = $locked->only(['clock_in_at', 'clock_out_at', 'worked_minutes', 'gross_pay', 'overtime_review']);
            $payrollItem = InterhubPayrollItem::query()->where('attendance_session_id', $locked->id)->lockForUpdate()->first();
            if ($payrollItem?->status === 'PAID') {
                throw new RuntimeException('This shift is already paid and cannot be changed through time-log correction.');
            }
            if ($payrollItem && ! $clockOut) {
                throw new RuntimeException('This shift is already included in payroll and cannot be reopened. Correct its time values before generating payroll.');
            }
            if (! $clockOut && InterhubAttendanceSession::query()->where('rider_profile_id', $locked->rider_profile_id)
                ->whereNull('clock_out_at')->where('id', '<>', $locked->id)->lockForUpdate()->exists()) {
                throw new RuntimeException('This driver already has another open shift. Close it before correcting this shift to an open state.');
            }
            $minutes = $clockOut ? intdiv($clockIn->diffInSeconds($clockOut), 60) : null;
            $pay = $minutes === null ? null : round(((float) $locked->daily_rate_snapshot * $minutes) / 480, 2, PHP_ROUND_HALF_UP);
            $locked->update([
                'clock_in_at' => $clockIn, 'clock_out_at' => $clockOut, 'worked_minutes' => $minutes,
                'active_driver_id' => $clockOut ? null : $locked->rider_profile_id,
                'gross_pay' => $pay, 'overtime_review' => $minutes !== null && $minutes > 480,
            ]);
            $payrollItem?->update([
                'worked_minutes' => $minutes,
                'amount' => $pay ?? 0,
                'status' => $minutes !== null && $minutes > 480 ? 'REVIEW_REQUIRED' : 'PENDING',
            ]);
            $this->event($locked, $actor, 'CORRECTED', $old, $locked->fresh()->only(['clock_in_at', 'clock_out_at', 'worked_minutes', 'gross_pay', 'overtime_review']), $reason);

            return $locked->refresh();
        }, 3);
    }

    public function issueQr(string $issuedByRole, int $centerId, ?RiderProfile $driver = null): string
    {
        $this->assertReady();
        if (! in_array($issuedByRole, ['LOGISTICS', 'RIDER'], true)) {
            throw new RuntimeException('The QR issuer role is invalid.');
        }
        if ($issuedByRole === 'RIDER' && (! $driver || (int) $driver->logistics_center_id !== $centerId || ! RiderVehicleType::isInterLogistics($driver->vehicle_type))) {
            throw new RuntimeException('Only an Interhub driver can issue a driver attendance QR.');
        }
        $token = bin2hex(random_bytes(24));
        DB::table('interhub_attendance_qr_tokens')->where('expires_at', '<', now()->subDay())->delete();
        DB::table('interhub_attendance_qr_tokens')->insert([
            'token_hash' => hash('sha256', $token), 'issued_by_role' => $issuedByRole,
            'rider_profile_id' => $issuedByRole === 'RIDER' ? $driver?->id : null,
            'logistics_center_id' => $centerId,
            'manifest_id' => null, 'action' => 'ATTENDANCE',
            'expires_at' => now()->addSeconds(20), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $token;
    }

    public function consumeQr(User $scanner, string $token, string $scannerRole): InterhubAttendanceSession
    {
        $this->assertReady();

        return DB::transaction(function () use ($scanner, $token, $scannerRole): InterhubAttendanceSession {
            $row = DB::table('interhub_attendance_qr_tokens')->where('token_hash', hash('sha256', trim($token)))->lockForUpdate()->first();
            if (! $row || Carbon::parse($row->expires_at)->isPast() || ($row->issued_by_role === 'RIDER' && $row->consumed_at)) {
                throw new RuntimeException('This QR expired or was already used. Refresh the code and scan again.');
            }
            if ($row->issued_by_role === $scannerRole) {
                throw new RuntimeException('The QR must be scanned by the other side.');
            }
            if ($scannerRole === 'RIDER') {
                $profile = $scanner->riderProfile;
                if (! $profile || ! RiderVehicleType::isInterLogistics($profile->vehicle_type) || (int) $profile->logistics_center_id !== (int) $row->logistics_center_id
                    || ($row->rider_profile_id !== null && (int) $profile->id !== (int) $row->rider_profile_id)) {
                    throw new RuntimeException('This timekeeping QR belongs to another driver.');
                }
            } else {
                if ((int) $scanner->logisticsCenter?->id !== (int) $row->logistics_center_id) {
                    throw new RuntimeException('This QR belongs to a different Logistics center.');
                }
            }
            $source = $row->issued_by_role === 'LOGISTICS' ? 'LOGISTICS_QR' : 'DRIVER_QR';
            $driverId = $row->issued_by_role === 'LOGISTICS' ? (int) $scanner->riderProfile->id : (int) $row->rider_profile_id;
            if ($row->issued_by_role === 'LOGISTICS') {
                $alreadyUsed = DB::table('interhub_attendance_qr_uses')->where('qr_token_id', $row->id)->where('rider_profile_id', $driverId)->exists();
                if ($alreadyUsed) {
                    throw new RuntimeException('This Logistics QR was already used by this driver. Wait for the next QR refresh.');
                }
            }
            $session = $this->clock($scanner, $driverId, (int) $row->logistics_center_id, $source);
            if ($row->issued_by_role === 'LOGISTICS') {
                DB::table('interhub_attendance_qr_uses')->insert([
                    'qr_token_id' => $row->id, 'rider_profile_id' => $driverId,
                    'attendance_session_id' => $session->id, 'used_at' => now(),
                ]);
            } else {
                DB::table('interhub_attendance_qr_tokens')->where('id', $row->id)->update(['consumed_at' => now(), 'updated_at' => now()]);
                DB::table('interhub_attendance_qr_uses')->insert([
                    'qr_token_id' => $row->id, 'rider_profile_id' => $driverId,
                    'attendance_session_id' => $session->id, 'used_at' => now(),
                ]);
            }
            return $session;
        }, 3);
    }

    public function createPayrollBatch(User $actor, int $centerId, Carbon $start, Carbon $end): InterhubPayrollBatch
    {
        $this->assertReady();
        if ($end->lt($start)) {
            throw new RuntimeException('The pay period end must be on or after its start date.');
        }

        return DB::transaction(function () use ($actor, $centerId, $start, $end): InterhubPayrollBatch {
            $batch = InterhubPayrollBatch::query()->firstOrCreate([
                'logistics_center_id' => $centerId, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
            ], ['created_by_user_id' => $actor->id]);
            $sessions = InterhubAttendanceSession::query()->where('logistics_center_id', $centerId)
                ->whereNotNull('clock_out_at')->whereBetween('clock_out_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
                ->whereDoesntHave('payrollItem')->get();
            foreach ($sessions as $session) {
                InterhubPayrollItem::query()->firstOrCreate(['attendance_session_id' => $session->id], [
                    'payroll_batch_id' => $batch->id, 'rider_profile_id' => $session->rider_profile_id,
                    'daily_rate_snapshot' => $session->daily_rate_snapshot, 'worked_minutes' => $session->worked_minutes,
                    'amount' => $session->gross_pay ?? 0, 'status' => $session->overtime_review ? 'REVIEW_REQUIRED' : 'PENDING',
                ]);
            }

            return $batch->refresh();
        }, 3);
    }

    public function regionForDriver(RiderProfile $driver): string
    {
        $code = strtoupper((string) ($driver->logisticsCenter?->address?->region_code ?? ''));
        $name = strtoupper((string) ($driver->logisticsCenter?->address?->region_name ?? ''));

        return (str_contains($code, '13') || str_contains($name, 'NATIONAL CAPITAL') || $name === 'NCR' || str_contains($name, 'METRO MANILA'))
            ? 'NCR'
            : 'PROVINCIAL';
    }

    public function driverDashboardData(RiderProfile $driver): array
    {
        if (! $this->ready()) {
            return [
                'timekeepingReady' => false, 'regionType' => null, 'effectiveRate' => null,
                'openSession' => null, 'attendanceHistory' => collect(),
                'currentMinutes' => 0, 'estimatedCurrentPay' => 0.0, 'todayMinutes' => 0,
                'todayStatus' => 'ABSENT', 'todayPayEstimate' => 0.0,
            ];
        }
        $driver->loadMissing('logisticsCenter.address', 'user');
        $region = $this->regionForDriver($driver);
        $open = $this->openSession((int) $driver->id);
        $currentMinutes = $open ? intdiv($open->clock_in_at->diffInSeconds(now()), 60) : 0;
        $todayAttendance = $this->todayAttendanceData($driver);
        $monthStart = now()->startOfMonth();
        $monthSessions = InterhubAttendanceSession::query()->where('rider_profile_id', $driver->id)
            ->where('clock_in_at', '>=', $monthStart)->get(['worked_minutes', 'gross_pay', 'clock_out_at']);
        $monthMinutes = (int) $monthSessions->whereNotNull('clock_out_at')->sum('worked_minutes')
            + ($open && $open->clock_in_at->greaterThanOrEqualTo($monthStart) ? $currentMinutes : 0);
        $openThisMonth = $open && $open->clock_in_at->greaterThanOrEqualTo($monthStart);
        $monthPay = (float) $monthSessions->whereNotNull('clock_out_at')->sum('gross_pay') + ($openThisMonth ? (float) round(((float) $open->daily_rate_snapshot * $currentMinutes) / 480, 2, PHP_ROUND_HALF_UP) : 0.0);

        return [
            'timekeepingReady' => true,
            'regionType' => $region,
            'effectiveRate' => $this->effectiveRate((int) $driver->id, $region),
            'openSession' => $open,
            'attendanceHistory' => InterhubAttendanceSession::query()->where('rider_profile_id', $driver->id)
                ->with('manifest', 'events.actor', 'payrollItem')->latest('clock_in_at')->limit(30)->get(),
            'currentMinutes' => $currentMinutes,
            'estimatedCurrentPay' => $open ? round(((float) $open->daily_rate_snapshot * $currentMinutes) / 480, 2, PHP_ROUND_HALF_UP) : 0.0,
            'todayMinutes' => $todayAttendance['worked_minutes'],
            'todayStatus' => $todayAttendance['status'],
            'todayPayEstimate' => $todayAttendance['pay_estimate'],
            'totalPeriodHours' => round($monthMinutes / 60, 2),
            'estimatedPeriodPay' => $monthPay,
        ];
    }

    public function attendanceRoster(int $centerId): \Illuminate\Support\Collection
    {
        $todayStart = now()->startOfDay();
        $todayEnd = now()->endOfDay();
        $openDriverIds = InterhubAttendanceSession::query()->where('logistics_center_id', $centerId)->whereNull('clock_out_at')->pluck('rider_profile_id');
        $drivers = RiderProfile::query()->where('logistics_center_id', $centerId)
            ->whereIn('vehicle_type', RiderVehicleType::interLogisticsValues())->with('user')
            ->where(fn ($query) => $query->whereIn('status', ['ACTIVE', 'WARNED'])->orWhereIn('id', $openDriverIds))
            ->where(fn ($query) => $query->whereHas('user', fn ($user) => $user->where('status', User::STATUS_ACTIVE))->orWhereIn('rider_profiles.id', $openDriverIds))
            ->orderBy('id')->get();
        $sessions = InterhubAttendanceSession::query()->where('logistics_center_id', $centerId)
            ->where('clock_in_at', '<', $todayEnd)->where(fn ($query) => $query->whereNull('clock_out_at')->orWhere('clock_out_at', '>', $todayStart))
            ->with('manifest')->get()->groupBy('rider_profile_id');

        return $drivers->map(function (RiderProfile $driver) use ($sessions, $todayStart, $todayEnd): array {
            $driverSessions = $sessions->get($driver->id, collect());
            $open = $driverSessions->first(fn (InterhubAttendanceSession $session): bool => $session->clock_out_at === null);
            $todayStartSession = $driverSessions->filter(fn (InterhubAttendanceSession $session): bool => $session->clock_in_at->greaterThanOrEqualTo($todayStart) && $session->clock_in_at->lessThanOrEqualTo($todayEnd))->sortBy('clock_in_at')->first();
            $startedToday = (bool) $todayStartSession;
            $minutes = (int) $driverSessions->sum(fn (InterhubAttendanceSession $session): int => $this->minutesWithin($session, $todayStart, $todayEnd));
            $estimate = round($driverSessions->sum(fn (InterhubAttendanceSession $session): float => ((float) $session->daily_rate_snapshot * $this->minutesWithin($session, $todayStart, $todayEnd)) / 480), 2, PHP_ROUND_HALF_UP);
            $status = $open ? 'ON SHIFT' : ($startedToday ? 'PRESENT' : 'ABSENT');

            return [
                'driver_id' => (int) $driver->id,
                'driver_name' => $driver->user?->name ?? 'Interhub driver',
                'status' => $status,
                'worked_minutes' => $minutes,
                'hours' => number_format($minutes / 60, 2),
                'clock_in_at' => $todayStartSession?->clock_in_at?->format('g:i A'),
                'manifest' => $open?->manifest?->manifest_number,
                'pay_estimate' => $estimate,
                'daily_target_minutes' => 480,
            ];
        });
    }

    public function todayAttendanceData(RiderProfile $driver): array
    {
        $todayStart = now()->startOfDay();
        $todayEnd = now()->endOfDay();
        $sessions = InterhubAttendanceSession::query()->where('rider_profile_id', $driver->id)
            ->where('clock_in_at', '<', $todayEnd)->where(fn ($query) => $query->whereNull('clock_out_at')->orWhere('clock_out_at', '>', $todayStart))->get();
        $startedToday = $sessions->contains(fn (InterhubAttendanceSession $session): bool => $session->clock_in_at->greaterThanOrEqualTo($todayStart) && $session->clock_in_at->lessThanOrEqualTo($todayEnd));
        $minutes = (int) $sessions->sum(fn (InterhubAttendanceSession $session): int => $this->minutesWithin($session, $todayStart, $todayEnd));

        return [
            'status' => $this->openSession((int) $driver->id) ? 'ON SHIFT' : ($startedToday ? 'PRESENT' : 'ABSENT'),
            'worked_minutes' => $minutes,
            'hours' => number_format($minutes / 60, 2),
            'pay_estimate' => round($sessions->sum(fn (InterhubAttendanceSession $session): float => ((float) $session->daily_rate_snapshot * $this->minutesWithin($session, $todayStart, $todayEnd)) / 480), 2, PHP_ROUND_HALF_UP),
            'daily_target_minutes' => 480,
        ];
    }

    private function currentManifestId(int $driverId, int $centerId): ?int
    {
        $ids = TransferManifest::query()->where('assigned_driver_rider_profile_id', $driverId)
            ->where('origin_logistics_center_id', $centerId)
            ->where(function ($query): void {
                $query->whereIn('status', [TransferManifest::RELEASED, TransferManifest::IN_TRANSIT, TransferManifest::ARRIVED, TransferManifest::PARTIAL, TransferManifest::EXCEPTION])
                    ->orWhere(fn ($accepted) => $accepted->where('status', TransferManifest::SEALED)->whereNotNull('driver_accepted_at'));
            })->pluck('id')->unique();

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    private function minutesWithin(InterhubAttendanceSession $session, Carbon $start, Carbon $end): int
    {
        $from = $session->clock_in_at->greaterThan($start) ? $session->clock_in_at : $start;
        $clockOut = $session->clock_out_at ?? now();
        $to = $clockOut->lessThan($end) ? $clockOut : $end;

        return $to->greaterThan($from) ? intdiv($from->diffInSeconds($to), 60) : 0;
    }

    private function event(InterhubAttendanceSession $session, User $actor, string $type, ?array $old, ?array $new, ?string $reason = null): void
    {
        InterhubAttendanceEvent::query()->create([
            'attendance_session_id' => $session->id, 'actor_user_id' => $actor->id, 'event_type' => $type,
            'old_values' => $old, 'new_values' => $new, 'reason' => $reason,
            'ip_address' => request()->ip(), 'created_at' => now(),
        ]);
    }

    private function assertReady(): void
    {
        if (! $this->ready()) {
            throw new RuntimeException('Interhub timekeeping is unavailable until its additive database migration is applied.');
        }
    }
}
