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

class HubToHubRiderSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('HUB_TO_HUB_RIDER_PASSWORD');
        if (! is_string($password) || $password === '') {
            throw new RuntimeException('Set HUB_TO_HUB_RIDER_PASSWORD before running this seeder.');
        }

        DB::transaction(function () use ($password): void {
            $centers = collect(['jnt@likhae.online', 'pagsanjan@likhae.online'])
                ->mapWithKeys(function (string $email): array {
                    $center = LogisticsCenter::query()
                        ->approvedActive()
                        ->whereHas('owner', fn ($query) => $query->where('email', $email))
                        ->with('address', 'owner')
                        ->sole();

                    if (! $center->address) {
                        throw new RuntimeException("The logistics hub for {$email} needs an address before its rider can be seeded.");
                    }

                    return [$email => $center];
                });

            $riders = [
                [
                    'first_name' => 'Juan Carlo',
                    'middle_initial' => 'M',
                    'last_name' => 'Likha',
                    'email' => 'jc@likhae.online',
                    'contact_number' => '09999990041',
                    'birthday' => '1994-05-18',
                    'hub_email' => 'jnt@likhae.online',
                    'plate_number' => 'SEED-JC-VAN-01',
                    'drivers_license_number' => 'SEED-JC-DL-01',
                ],
                [
                    'first_name' => 'Carlo Joaquin',
                    'middle_initial' => 'J',
                    'last_name' => 'Likha',
                    'email' => 'cj@likhae.online',
                    'contact_number' => '09999990042',
                    'birthday' => '1992-09-07',
                    'hub_email' => 'pagsanjan@likhae.online',
                    'plate_number' => 'SEED-CJ-VAN-01',
                    'drivers_license_number' => 'SEED-CJ-DL-01',
                ],
            ];

            foreach ($riders as $details) {
                $this->seedRider($details, $centers->get($details['hub_email']), $password);
            }
        });
    }

    /** @param array<string, string> $details */
    private function seedRider(array $details, LogisticsCenter $center, string $password): void
    {
        $user = User::query()->firstOrNew(['email' => $details['email']]);
        if ($user->exists) {
            if ($user->account_type !== User::TYPE_RIDER) {
                throw new RuntimeException("{$details['email']} already belongs to a non-rider account.");
            }
            if ($user->status !== User::STATUS_ACTIVE || $user->email_verified_at === null) {
                throw new RuntimeException("{$details['email']} exists but is not an active, verified rider account.");
            }
        } else {
            $user->fill([
                'account_type' => User::TYPE_RIDER,
                'first_name' => $details['first_name'],
                'middle_initial' => $details['middle_initial'],
                'last_name' => $details['last_name'],
                'sex' => 'MALE',
                'contact_number' => $details['contact_number'],
                'birthday' => $details['birthday'],
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => now(),
                'password' => Hash::make($password),
            ]);
            $user->save();
        }

        $profile = RiderProfile::query()->firstOrNew(['user_id' => $user->id]);
        if ($profile->exists) {
            if ((int) $profile->logistics_center_id !== (int) $center->id) {
                throw new RuntimeException("{$details['email']} already belongs to a different logistics hub.");
            }
            if ($profile->vehicle_type !== RiderVehicleType::VAN || $profile->status !== 'ACTIVE') {
                throw new RuntimeException("{$details['email']} already has a rider profile that does not match this active van seed.");
            }
        } else {
            $this->assertUniqueContactNumber($details['contact_number'], $user);
            $this->assertUniqueVehicleDetails($details['plate_number'], $details['drivers_license_number']);

            $profile->fill([
                'logistics_center_id' => $center->id,
                'vehicle_type' => RiderVehicleType::VAN,
                'approved_parcel_capacity' => 25,
                'approved_weight_capacity_kg' => 500,
                'approved_volume_capacity_liters' => 3000,
                'plate_number' => $details['plate_number'],
                'drivers_license_number' => $details['drivers_license_number'],
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
                'latitude' => $base->latitude,
                'longitude' => $base->longitude,
                'is_default' => ! $user->addresses()->where('is_default', true)->exists(),
            ],
        );
    }

    private function assertUniqueContactNumber(string $contactNumber, User $user): void
    {
        if (User::query()->where('contact_number', $contactNumber)->whereKeyNot($user->id)->exists()) {
            throw new RuntimeException("The seeded contact number {$contactNumber} is already assigned to another account.");
        }
    }

    private function assertUniqueVehicleDetails(string $plateNumber, string $licenseNumber): void
    {
        if (RiderProfile::query()->where('plate_number', $plateNumber)->orWhere('drivers_license_number', $licenseNumber)->exists()) {
            throw new RuntimeException('A seeded van plate or license number is already assigned to another rider.');
        }
    }
}
