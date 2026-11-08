<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\PhilippineAddressController;
use App\Models\Auth\ApplicationDocument;
use App\Models\Auth\RegistrationApplication;
use App\Models\Buyer\Address;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Rider\RiderApplicationData;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use App\Support\PhilippineRegionCatalog;
use App\Support\RiderVehicleType;
use Carbon\Carbon;
use Database\Seeders\QaRiderDriverSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class QaRiderDriverSeederTest extends TestCase
{
    /** @var array<int, array{business:string,region:string,province:string,municipality:string,postal:string}> */
    private const QA_CENTER_DETAILS = [
        1 => ['business' => 'Highland Cargo Link', 'region' => 'CAR', 'province' => 'Benguet', 'municipality' => 'La Trinidad', 'postal' => '2601'],
        2 => ['business' => 'MetroSwift Parcel Hub', 'region' => 'NCR', 'province' => 'National Capital Region (NCR)', 'municipality' => 'Quezon City', 'postal' => '1121'],
        3 => ['business' => 'Ilocos Route Cargo', 'region' => 'I', 'province' => 'Ilocos Norte', 'municipality' => 'City of Laoag', 'postal' => '2900'],
        4 => ['business' => 'Cagayan Valley Transit Cargo', 'region' => 'II', 'province' => 'Cagayan', 'municipality' => 'Tuguegarao City', 'postal' => '3500'],
        5 => ['business' => 'Pampanga Express Dispatch', 'region' => 'III', 'province' => 'Pampanga', 'municipality' => 'City of San Fernando', 'postal' => '2000'],
        6 => ['business' => 'Laguna Fulfill Logistics', 'region' => 'IV-A', 'province' => 'Laguna', 'municipality' => 'City of Santa Rosa', 'postal' => '4026'],
        7 => ['business' => 'Mindoro Portline Couriers', 'region' => 'IV-B', 'province' => 'Oriental Mindoro', 'municipality' => 'City of Calapan', 'postal' => '5200'],
        8 => ['business' => 'Bicol LastMile Transit', 'region' => 'V', 'province' => 'Albay', 'municipality' => 'City of Legazpi', 'postal' => '4500'],
        9 => ['business' => 'Iloilo Fleetways Courier', 'region' => 'VI', 'province' => 'Iloilo', 'municipality' => 'Pavia', 'postal' => '5001'],
        10 => ['business' => 'Negros Coast Cargo', 'region' => 'NIR', 'province' => 'Negros Occidental', 'municipality' => 'City of Silay', 'postal' => '6116'],
        11 => ['business' => 'Cebu Citylink Delivery', 'region' => 'VII', 'province' => 'Cebu', 'municipality' => 'Minglanilla', 'postal' => '6046'],
        12 => ['business' => 'Leyte Linkhaul Parcel', 'region' => 'VIII', 'province' => 'Leyte', 'municipality' => 'Palo', 'postal' => '6501'],
        13 => ['business' => 'Pagadian Swift Dispatch', 'region' => 'IX', 'province' => 'Zamboanga del Sur', 'municipality' => 'City of Pagadian', 'postal' => '7016'],
        14 => ['business' => 'Tagoloan Cargo Exchange', 'region' => 'X', 'province' => 'Misamis Oriental', 'municipality' => 'Tagoloan', 'postal' => '9001'],
        15 => ['business' => 'Digos Southern Route', 'region' => 'XI', 'province' => 'Davao del Sur', 'municipality' => 'City of Digos', 'postal' => '8002'],
        16 => ['business' => 'Koronadal Parcel Net', 'region' => 'XII', 'province' => 'South Cotabato', 'municipality' => 'City of Koronadal', 'postal' => '9506'],
        17 => ['business' => 'Agusan Freightway', 'region' => 'XIII', 'province' => 'Agusan del Norte', 'municipality' => 'City of Cabadbaran', 'postal' => '8605'],
        18 => ['business' => 'Marawi Parcel Relay', 'region' => 'BARMM', 'province' => 'Lanao del Sur', 'municipality' => 'City of Marawi', 'postal' => '9700'],
    ];

    public function test_fixture_manifest_has_two_valid_personnel_per_center(): void
    {
        $fixtures = QaRiderDriverSeeder::fixtures();

        $this->assertCount(36, $fixtures);
        $this->assertSame(range(1, 36), array_column($fixtures, 'id'));
        $this->assertCount(36, array_unique(array_map(
            fn (array $fixture): string => $fixture['email'],
            $fixtures,
        )));
        foreach (['contact_number', 'plate_number', 'drivers_license_number'] as $uniqueField) {
            $this->assertCount(36, array_unique(array_column($fixtures, $uniqueField)), "{$uniqueField} values must be unique.");
        }

        foreach (range(1, 18) as $center) {
            $assigned = array_values(array_filter($fixtures, fn (array $fixture): bool => $fixture['center'] === $center));
            $this->assertCount(2, $assigned, "Center {$center} must have exactly two manifest records.");
            $this->assertSame(['LOCAL', 'INTER-HUB'], array_column($assigned, 'role'));
        }

        foreach ($fixtures as $fixture) {
            $age = Carbon::parse($fixture['birthday'])->age;
            $this->assertGreaterThanOrEqual(18, $age, "Fixture {$fixture['id']} must be an adult.");

            if ($fixture['role'] === 'LOCAL') {
                $this->assertContains($fixture['vehicle'], ['motorcycle', 'tricycle']);
            } else {
                $this->assertContains($fixture['vehicle'], ['van', 'closed_van', 'truck', 'car']);
            }
        }

        $this->assertSame('09990010001', $fixtures[0]['contact_number']);
        $this->assertSame('09990010036', $fixtures[35]['contact_number']);
    }

    public function test_seeder_refuses_production_before_opening_a_database_connection(): void
    {
        $this->app['env'] = 'production';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('explicit production opt-in');

        (new QaRiderDriverSeeder)->run();
    }

    public function test_safe_target_requires_an_explicit_qa_or_test_database_name(): void
    {
        QaRiderDriverSeeder::assertSafeTarget('testing', 'likhae_test');
        QaRiderDriverSeeder::assertSafeTarget('qa', 'likhae_qa');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('database name containing a test or QA marker');
        QaRiderDriverSeeder::assertSafeTarget('qa', 'likhae');
    }

    public function test_production_target_requires_an_explicit_opt_in_and_exact_database_name(): void
    {
        QaRiderDriverSeeder::assertSafeTarget('production', 'likhae', true);

        try {
            QaRiderDriverSeeder::assertSafeTarget('production', 'likhae', false);
            $this->fail('Production seeding without its explicit opt-in must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('explicit production opt-in', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exact production database name likhae');
        QaRiderDriverSeeder::assertSafeTarget('production', 'likhae_test', true);
    }

    public function test_seeder_is_idempotent_and_accounts_are_visible_only_to_their_logistics_center(): void
    {
        Storage::fake('public');
        Storage::fake('registrations');

        $startingUserCount = User::query()->count();
        $startingAddressCount = Address::query()->count();
        $startingApplicationCount = RegistrationApplication::query()->count();
        $startingProfileCount = RiderProfile::query()->count();
        $startingDocumentCount = ApplicationDocument::query()->count();
        DB::beginTransaction();

        try {
            $centers = $this->createQaCenterFixtures();
            (new QaRiderDriverSeeder)->run();

            $fixtures = QaRiderDriverSeeder::fixtures();
            $emails = array_column($fixtures, 'email');
            $this->assertSame(36, User::query()->whereIn('email', $emails)->count());
            $this->assertSame(36, RegistrationApplication::query()->whereIn('application_number', array_map(
                fn (array $fixture): string => sprintf('QA-RIDER-DRIVER-%04d', $fixture['id']),
                $fixtures,
            ))->count());
            $this->assertSame(36, RiderProfile::query()->count());
            $this->assertSame(108, ApplicationDocument::query()->count());
            $this->assertSame($startingUserCount + 55, User::query()->count());
            $this->assertSame($startingAddressCount + 54, Address::query()->count());
            $this->assertSame($startingApplicationCount + 36, RegistrationApplication::query()->count());
            $this->assertSame($startingProfileCount + 36, RiderProfile::query()->count());
            $this->assertSame($startingDocumentCount + 108, ApplicationDocument::query()->count());

            foreach ($fixtures as $fixture) {
                $user = User::query()->where('email', $fixture['email'])->firstOrFail();
                $application = RegistrationApplication::query()->where('user_id', $user->id)->firstOrFail();
                $applicationData = RiderApplicationData::query()->where('registration_application_id', $application->id)->firstOrFail();
                $profile = RiderProfile::query()->where('user_id', $user->id)->firstOrFail();
                $address = $user->addresses()->where('label', 'QA Rider Home')->firstOrFail();

                $this->assertSame(User::TYPE_RIDER, $user->account_type);
                $this->assertSame(User::STATUS_ACTIVE, $user->status);
                $this->assertNotNull($user->email_verified_at);
                $this->assertTrue(Hash::check('Password1', $user->password));
                $this->assertGreaterThanOrEqual(18, $user->birthday->age);
                $this->assertSame(RegistrationApplication::STATUS_APPROVED, $application->status);
                $this->assertStringContainsString(QaRiderDriverSeeder::FIXTURE_MARKER, $application->decision_notes);
                $this->assertSame($fixture['vehicle'], $applicationData->vehicle_type);
                $this->assertSame((int) $centers[$fixture['center']]->id, (int) $profile->logistics_center_id);
                $this->assertSame('ACTIVE', $profile->status);
                $this->assertSame($fixture['plate_number'], $profile->plate_number);
                $this->assertSame($fixture['drivers_license_number'], $profile->drivers_license_number);
                $this->assertSame($fixture['role'], RiderVehicleType::isLocalBarangay($profile->vehicle_type) ? 'LOCAL' : 'INTER-HUB');
                $this->assertTrue(PhilippineAddressController::selectionIsValid(
                    (string) $address->region_code,
                    (string) $address->region_name,
                    (string) $address->province_code,
                    (string) $address->province_name,
                    (string) $address->municipality_code,
                    (string) $address->municipality_name,
                    (string) $address->barangay_code,
                    (string) $address->barangay_name,
                ));

                $documents = ApplicationDocument::query()
                    ->where('registration_application_id', $application->id)
                    ->get();
                $this->assertCount(3, $documents);
                foreach ($documents as $document) {
                    $this->assertSame('VERIFIED', $document->verification_status);
                    $this->assertStringContainsString('SAMPLE-TEST-ONLY', $document->original_name);
                    $this->assertTrue(Storage::disk('registrations')->exists($document->file_path));
                    $this->assertStringContainsString('SAMPLE - TEST ONLY', Storage::disk('registrations')->get($document->file_path));
                }

                $this->assertTrue(Storage::disk('public')->exists($user->profile_photo_path));
            }

            foreach (range(1, 18) as $centerNumber) {
                $center = $centers[$centerNumber];
                $response = $this->actingAs($center->owner)->get(route('logistics.riders'));
                $response->assertOk()->assertViewHas('riders', function ($riders) use ($center): bool {
                    $profiles = $riders->getCollection();

                    return $riders->total() === 2
                        && $profiles->every(fn (RiderProfile $profile): bool => (int) $profile->logistics_center_id === (int) $center->id)
                        && $profiles->filter(fn (RiderProfile $profile): bool => $profile->vehicleRole() === 'LOCAL_BARANGAY')->count() === 1
                        && $profiles->filter(fn (RiderProfile $profile): bool => $profile->vehicleRole() === 'INTER_LOGISTICS')->count() === 1;
                });
            }

            $firstOwner = $centers[1]->owner;
            $firstResponse = $this->actingAs($firstOwner)->get(route('logistics.riders'));
            $firstResponse->assertSee('john@likhae.online')
                ->assertSee('mark@likhae.online')
                ->assertSee('QA-LOGISTICS-001')
                ->assertSee('APPROVED')
                ->assertSee('ACTIVE')
                ->assertDontSee('christian@likhae.online');

            $outsideProfile = RiderProfile::query()
                ->whereHas('user', fn ($query) => $query->where('email', 'christian@likhae.online'))
                ->firstOrFail();
            $this->actingAs($firstOwner)
                ->get(route('logistics.riders.show', $outsideProfile))
                ->assertForbidden();

            Auth::logout();
            $john = User::query()->where('email', 'john@likhae.online')->firstOrFail();
            $this->post(route('logistics.login.store'), ['email' => $john->email, 'password' => 'Password1'])
                ->assertRedirect();
            $this->assertAuthenticatedAs($john);
            $this->get(route('rider.shipments'))->assertOk();
            $this->get(route('rider.transfers'))->assertForbidden();

            Auth::logout();
            $mark = User::query()->where('email', 'mark@likhae.online')->firstOrFail();
            $this->post(route('logistics.login.store'), ['email' => $mark->email, 'password' => 'Password1'])
                ->assertRedirect();
            $this->assertAuthenticatedAs($mark);
            $this->get(route('rider.transfers'))->assertOk();
            $this->get(route('rider.shipments'))->assertForbidden();

            (new QaRiderDriverSeeder)->run();
            $this->assertSame(36, User::query()->whereIn('email', $emails)->count());
            $this->assertSame(36, RegistrationApplication::query()->whereIn('application_number', array_map(
                fn (array $fixture): string => sprintf('QA-RIDER-DRIVER-%04d', $fixture['id']),
                $fixtures,
            ))->count());
            $this->assertSame(36, RiderProfile::query()->count());
            $this->assertSame(108, ApplicationDocument::query()->count());
            $this->assertSame($startingUserCount + 55, User::query()->count());
            $this->assertSame($startingAddressCount + 54, Address::query()->count());
        } finally {
            DB::rollBack();
        }
    }

    /** @return array<int, LogisticsCenter> */
    private function createQaCenterFixtures(): array
    {
        $controller = new PhilippineAddressController;
        $regionCatalog = PhilippineRegionCatalog::all();
        $centers = [];

        foreach (self::QA_CENTER_DETAILS as $number => $details) {
            $regionMeta = $regionCatalog[$details['region']];
            $region = collect($controller->regions()->getData(true))->firstWhere('code', $regionMeta['psgc_code']);
            $this->assertNotNull($region, "Missing PSGC region {$details['region']} in the local reference data.");

            $province = collect($controller->provinces($regionMeta['psgc_code'])->getData(true))
                ->first(fn (array $row): bool => strcasecmp(trim((string) ($row['name'] ?? '')), $details['province']) === 0)
                ?? $region;
            $municipality = collect($controller->municipalities((string) $province['code'])->getData(true))
                ->first(fn (array $row): bool => strcasecmp(trim((string) ($row['name'] ?? '')), $details['municipality']) === 0);
            $this->assertNotNull($municipality, "Missing PSGC municipality {$details['municipality']} in center {$number}.");

            $barangay = collect(PhilippineAddressController::barangayRecordsForMunicipality((string) $municipality['code']))
                ->filter(fn ($row): bool => is_array($row) && filled($row['code'] ?? null) && filled($row['name'] ?? null))
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->first();
            $this->assertNotNull($barangay, "Missing barangays for center {$number}.");

            $owner = User::query()->create([
                'account_type' => User::TYPE_LOGISTICS,
                'first_name' => 'QA',
                'middle_initial' => sprintf('%02d', $number),
                'last_name' => 'Center Owner',
                'sex' => 'PREFER_NOT_TO_SAY',
                'email' => sprintf('qa-logistics-%03d@qa-fixtures.invalid', $number),
                'contact_number' => sprintf('088800000%02d', $number),
                'birthday' => '1980-01-01',
                'email_verified_at' => now(),
                'password' => Hash::make('QA-center-owner-test-only'),
                'status' => User::STATUS_ACTIVE,
            ]);

            $address = Address::query()->create([
                'user_id' => $owner->id,
                'label' => 'QA Center Address',
                'recipient_name' => $owner->name,
                'contact_number' => $owner->contact_number,
                'region_code' => (string) $region['code'],
                'region_name' => (string) $region['name'],
                'province_code' => (string) $province['code'],
                'province_name' => (string) $province['name'],
                'municipality_code' => (string) $municipality['code'],
                'municipality_name' => (string) $municipality['name'],
                'barangay_code' => (string) $barangay['code'],
                'barangay_name' => (string) $barangay['name'],
                'postal_code' => $details['postal'],
                'house_number' => sprintf('QA Hub %02d', $number),
                'street_address' => 'Test Logistics Access Road',
                'landmark' => 'Synthetic QA center fixture; not a public location.',
                'is_default' => true,
            ]);

            $centers[$number] = LogisticsCenter::query()->create([
                'owner_user_id' => $owner->id,
                'address_id' => $address->id,
                'code' => sprintf('QA-LOGISTICS-%03d', $number),
                'business_name' => $details['business'],
                'status' => 'ACTIVE',
                'approved_by_user_id' => $owner->id,
                'approved_at' => now(),
            ]);
        }

        return $centers;
    }
}
