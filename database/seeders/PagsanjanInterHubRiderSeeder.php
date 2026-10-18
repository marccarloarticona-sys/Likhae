<?php

namespace Database\Seeders;

use App\Models\Buyer\Address;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use App\Support\RiderVehicleType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class PagsanjanInterHubRiderSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('PAGSANJAN_INTER_HUB_RIDER_PASSWORD');
        if (! is_string($password) || $password === '') {
            throw new RuntimeException('Set PAGSANJAN_INTER_HUB_RIDER_PASSWORD before running this seeder.');
        }

        DB::transaction(function () use ($password): void {
            $center = LogisticsCenter::query()
                ->approvedActive()
                ->whereHas('owner', fn ($query) => $query->where('email', 'pagsanjan@likhae.online'))
                ->with('address')
                ->sole();
            if (! $center->address) {
                throw new RuntimeException('The Pagsanjan hub needs an address before its rider can be seeded.');
            }

            $user = User::query()->firstOrNew(['email' => 'marc@likhae.online']);
            if ($user->exists && $user->account_type !== User::TYPE_RIDER) {
                throw new RuntimeException('marc@likhae.online belongs to a non-rider account.');
            }
            if (! $user->exists) {
                $user->fill([
                    'account_type' => User::TYPE_RIDER,
                    'first_name' => 'Marc',
                    'last_name' => 'Rider', // Seed placeholder until the legal name is supplied.
                    'sex' => 'PREFER_NOT_TO_SAY',
                    'contact_number' => '09999990023', // Seed placeholder, not a verified contact.
                    'birthday' => '1990-01-01', // Seed placeholder, not a verified birth date.
                    'status' => User::STATUS_ACTIVE,
                    'email_verified_at' => now(),
                    'password' => Hash::make($password),
                ]);
                $user->save();
            }

            $profile = RiderProfile::query()->firstOrNew(['user_id' => $user->id]);
            if ($profile->exists && (int) $profile->logistics_center_id !== (int) $center->id) {
                throw new RuntimeException('Marc already belongs to a different logistics hub.');
            }
            if (! $profile->exists) {
                $profile->fill([
                    'logistics_center_id' => $center->id,
                    'vehicle_type' => RiderVehicleType::VAN,
                    'approved_parcel_capacity' => 25,
                    'approved_weight_capacity_kg' => 500,
                    'approved_volume_capacity_liters' => 3000,
                    'plate_number' => 'SEED-MARC-VAN-01', // Replace with the registered plate.
                    'drivers_license_number' => 'SEED-MARC-DL-01', // Replace with the verified license.
                    'status' => 'ACTIVE',
                    'approved_by_user_id' => $center->owner_user_id,
                    'approved_at' => now(),
                ]);
                $profile->save();
            }

            $base = $center->address;
            Address::query()->firstOrCreate(
                ['user_id' => $user->id, 'label' => 'Logistics base'],
                [
                    'recipient_name' => $user->name,
                    'contact_number' => $user->contact_number,
                    'province_code' => $base->province_code,
                    'province_name' => $base->province_name,
                    'municipality_code' => $base->municipality_code,
                    'municipality_name' => $base->municipality_name,
                    'barangay_code' => $base->barangay_code,
                    'barangay_name' => $base->barangay_name,
                    'postal_code' => $base->postal_code,
                    'house_number' => $base->house_number,
                    'street_address' => $base->street_address,
                    'landmark' => $base->landmark,
                    'is_default' => true,
                ],
            );
        });
    }
}
