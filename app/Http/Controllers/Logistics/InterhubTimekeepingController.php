<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Rider\InterhubAttendanceSession;
use App\Models\Rider\InterhubDriverRate;
use App\Models\Rider\InterhubPayrollBatch;
use App\Models\Rider\InterhubPayrollItem;
use App\Models\Rider\RiderProfile;
use App\Services\Fulfillment\InterhubTimekeepingService;
use App\Support\RiderVehicleType;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class InterhubTimekeepingController extends Controller
{
    public function __construct(private readonly InterhubTimekeepingService $timekeeping) {}

    public function logisticsData(Request $request): array
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);
        if (! $this->timekeeping->ready()) {
            return ['timekeepingReady' => false, 'interhubDrivers' => collect(), 'interhubSessions' => collect(), 'interhubRates' => collect(), 'interhubPayrollBatches' => collect(), 'interhubAttendanceRoster' => collect()];
        }
        $openDriverIds = InterhubAttendanceSession::query()->where('logistics_center_id', $center->id)->whereNull('clock_out_at')->pluck('rider_profile_id');
        $drivers = RiderProfile::query()->where('logistics_center_id', $center->id)
            ->whereIn('vehicle_type', RiderVehicleType::interLogisticsValues())->with('user', 'logisticsCenter.address')
            ->where(fn ($query) => $query->whereIn('status', ['ACTIVE', 'WARNED'])->orWhereIn('id', $openDriverIds))
            ->where(function ($query) use ($openDriverIds): void {
                $query->whereHas('user', fn ($user) => $user->where('status', 'ACTIVE'))
                    ->orWhereIn('rider_profiles.id', $openDriverIds);
            })->orderBy('id')->get();
        $sessions = InterhubAttendanceSession::query()->where('logistics_center_id', $center->id)
            ->with(['riderProfile.user', 'manifest', 'rate', 'events.actor', 'payrollItem'])->latest('clock_in_at')->limit(60)->get();
        $batches = InterhubPayrollBatch::query()->where('logistics_center_id', $center->id)
            ->with(['items.riderProfile.user', 'items.session.manifest'])->latest('period_end')->paginate(12, ['*'], 'interhub_payroll_page')
            ->appends(request()->except('tab') + ['tab' => 'interhub-payouts']);

        return [
            'timekeepingReady' => true,
            'interhubDrivers' => $drivers,
            'interhubSessions' => $sessions,
            'interhubAttendanceRoster' => $this->timekeeping->attendanceRoster((int) $center->id),
            'interhubRates' => InterhubDriverRate::query()->where('logistics_center_id', $center->id)->with('riderProfile.user', 'creator')->latest('effective_from')->limit(100)->get(),
            'interhubPayrollBatches' => $batches,
        ];
    }

    public function saveRate(Request $request): RedirectResponse
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);
        abort_unless($this->timekeeping->ready(), 503);
        $data = $request->validate([
            'rider_profile_id' => ['required', 'integer'],
            'daily_rate' => ['required', 'numeric', 'decimal:0,4', 'min:0.01', 'max:100000'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ]);
        $driver = RiderProfile::query()->whereKey($data['rider_profile_id'])->where('logistics_center_id', $center->id)
            ->whereIn('vehicle_type', RiderVehicleType::interLogisticsValues())->with('logisticsCenter.address', 'user')->firstOrFail();
        $regionType = $this->timekeeping->regionForDriver($driver);
        $existingRate = InterhubDriverRate::query()->where([
            'rider_profile_id' => $driver->id, 'region_type' => $regionType, 'effective_from' => $data['effective_from'],
        ])->first();
        if ($existingRate && number_format((float) $existingRate->daily_rate, 4, '.', '') !== number_format((float) $data['daily_rate'], 4, '.', '')) {
            return back()->withErrors(['daily_rate' => 'A rate already starts on this date. Choose a new effective date so rate history stays intact.']);
        }
        if (! $existingRate) {
            InterhubDriverRate::query()->create([
                'rider_profile_id' => $driver->id, 'region_type' => $regionType, 'effective_from' => $data['effective_from'],
                'logistics_center_id' => $center->id, 'daily_rate' => $data['daily_rate'], 'created_by_user_id' => $request->user()->id,
            ]);
        }

        return back()->with('status', $regionType.' daily rate saved for '.$driver->user?->name.'. New shifts on or after '.$data['effective_from'].' will use this rate.');
    }

    public function logisticsQr(Request $request, InterhubTimekeepingService $service): JsonResponse
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);
        try {
            $token = $service->issueQr('LOGISTICS', (int) $center->id);
            return $this->qrResponse($token, 'AUTO');
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function driverQr(Request $request, InterhubTimekeepingService $service): JsonResponse
    {
        $driver = $request->user()->riderProfile;
        abort_unless($driver && RiderVehicleType::isInterLogistics($driver->vehicle_type), 403);
        try {
            $token = $service->issueQr('RIDER', (int) $driver->logistics_center_id, $driver);
            return $this->qrResponse($token, $service->openSession((int) $driver->id) ? 'TIME_OUT' : 'TIME_IN', $service->todayAttendanceData($driver));
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function scanByDriver(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:48']]);
        return $this->consume($request, $data['token'], 'RIDER');
    }

    public function scanByLogistics(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:48']]);
        return $this->consume($request, $data['token'], 'LOGISTICS');
    }

    public function activeSessions(Request $request): JsonResponse
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);
        abort_unless($this->timekeeping->ready(), 503);
        return response()->json([
            'success' => true,
            'attendance' => $this->timekeeping->attendanceRoster((int) $center->id)->values(),
        ])->header('Cache-Control', 'no-store, private, max-age=0');
    }

    public function correct(Request $request, int $session): RedirectResponse
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);
        $model = InterhubAttendanceSession::query()->whereKey($session)->where('logistics_center_id', $center->id)->firstOrFail();
        $data = $request->validate([
            'clock_in_at' => ['required', 'date'], 'clock_out_at' => ['nullable', 'date', 'after:clock_in_at'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        try {
            $this->timekeeping->correct($model, $request->user(), Carbon::parse($data['clock_in_at']), filled($data['clock_out_at'] ?? null) ? Carbon::parse($data['clock_out_at']) : null, trim($data['reason']));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['attendance' => $exception->getMessage()]);
        }

        return back()->with('status', 'Time log corrected. The original values, new values, reason, and Logistics actor are in its audit history.');
    }

    public function generatePayroll(Request $request): RedirectResponse
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);
        $data = $request->validate(['period_start' => ['required', 'date_format:Y-m-d'], 'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start']]);
        try {
            $batch = $this->timekeeping->createPayrollBatch($request->user(), (int) $center->id, Carbon::parse($data['period_start']), Carbon::parse($data['period_end']));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['payroll' => $exception->getMessage()]);
        }

        return redirect()->route('logistics.remittances', ['tab' => 'interhub-payouts'])->with('status', 'Payroll period generated. Closed shifts in this date range were added once.')->with('payroll_batch_id', $batch->id);
    }

    public function recordPayment(Request $request, int $item): RedirectResponse
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center && $this->timekeeping->ready(), 403);
        $data = $request->validate([
            'payment_reference' => ['required', 'string', 'max:160'],
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'payment_notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $path = $data['proof']->store('interhub-payroll', 'local');
        try {
            DB::transaction(function () use ($item, $center, $request, $data, $path): void {
                $payrollItem = InterhubPayrollItem::query()->whereKey($item)
                    ->whereHas('batch', fn ($query) => $query->where('logistics_center_id', $center->id))
                    ->lockForUpdate()->firstOrFail();
                abort_unless($payrollItem->status === 'PENDING', 409, 'This payroll item is paid or held for overtime review.');
                $payrollItem->update([
                    'status' => 'PAID', 'payment_reference' => trim($data['payment_reference']), 'proof_path' => $path,
                    'payment_notes' => $data['payment_notes'] ?? null, 'recorded_by_user_id' => $request->user()->id, 'paid_at' => now(),
                ]);
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return back()->with('status', 'Interhub payroll payment recorded with its transfer reference and proof.');
    }

    public function proof(Request $request, int $item): Response
    {
        $center = $request->user()->logisticsCenter;
        abort_unless($center, 403);
        $payrollItem = InterhubPayrollItem::query()->whereKey($item)->whereHas('batch', fn ($query) => $query->where('logistics_center_id', $center->id))->firstOrFail();
        abort_unless($payrollItem->proof_path && Storage::disk('local')->exists($payrollItem->proof_path), 404);
        return Storage::disk('local')->response($payrollItem->proof_path);
    }

    public function driverProof(Request $request, int $item): Response
    {
        $driver = $request->user()->riderProfile;
        abort_unless($driver && RiderVehicleType::isInterLogistics($driver->vehicle_type), 403);
        $payrollItem = InterhubPayrollItem::query()->whereKey($item)->where('rider_profile_id', $driver->id)->firstOrFail();
        abort_unless($payrollItem->proof_path && Storage::disk('local')->exists($payrollItem->proof_path), 404);
        return Storage::disk('local')->response($payrollItem->proof_path);
    }

    private function consume(Request $request, string $token, string $scannerRole): JsonResponse
    {
        try {
            $session = $this->timekeeping->consumeQr($request->user(), $token, $scannerRole);
            return response()->json([
                'success' => true,
                'message' => $session->clock_out_at
                    ? 'Time Out recorded at '.$session->clock_out_at->format('M j, Y g:i A').'. Shift: '.intdiv((int) $session->worked_minutes, 60).'h '.((int) $session->worked_minutes % 60).'m · PHP '.number_format((float) $session->gross_pay, 2).'.'
                    : 'Time In recorded at '.$session->clock_in_at->format('M j, Y g:i A').'. Your hours and pay estimate will update in today’s attendance.',
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    private function qrResponse(string $token, string $action, ?array $todayAttendance = null): JsonResponse
    {
        $qr = QrCode::create($token)->setSize(420)->setMargin(4);
        $result = (new SvgWriter())->write($qr);
        $payload = [
            'success' => true, 'qr_data_uri' => $result->getDataUri(), 'action' => $action,
            'expires_in_seconds' => 20,
        ];
        if ($todayAttendance !== null) {
            $payload['today_attendance'] = $todayAttendance;
        }
        return response()->json($payload)->header('Cache-Control', 'no-store, private, max-age=0');
    }
}
