<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\RiderVehicleType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRiderOperationalType
{
    public function handle(Request $request, Closure $next, string $type): Response
    {
        $rider = $request->user()?->riderProfile;
        abort_unless(
            $request->user()?->isAccountType(User::TYPE_RIDER)
            && $rider?->status === 'ACTIVE',
            403,
        );

        $allowed = match (strtoupper($type)) {
            RiderVehicleType::ROLE_LOCAL_BARANGAY => RiderVehicleType::isLocalBarangay($rider->vehicle_type),
            RiderVehicleType::ROLE_INTER_LOGISTICS => RiderVehicleType::isInterLogistics($rider->vehicle_type),
            default => false,
        };

        abort_unless($allowed, 403);

        return $next($request);
    }
}
