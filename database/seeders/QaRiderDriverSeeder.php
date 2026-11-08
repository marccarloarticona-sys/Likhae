<?php

namespace Database\Seeders;

use App\Http\Controllers\Auth\PhilippineAddressController;
use App\Models\Admin\AuditLog;
use App\Models\Auth\ApplicationDocument;
use App\Models\Auth\RegistrationApplication;
use App\Models\Buyer\Address;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Rider\RiderApplicationData;
use App\Models\Rider\RiderProfile;
use App\Models\User;
use App\Support\RiderVehicleType;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Creates synthetic rider fixtures only after explicit environment and target checks.
 * Production requires a one-command opt-in and the exact `likhae` database. This
 * intentionally does not impersonate a logistics owner: an inactive BUYER fixture
 * actor is recorded as the approver, and sample documents remain visibly marked.
 */
class QaRiderDriverSeeder extends Seeder
{
    public const FIXTURE_MARKER = 'QA_FIXTURE:rider-driver:v1';

    private const ACTOR_EMAIL = 'qa-rider-driver-seeder@qa-fixtures.invalid';
    private const ACTOR_CONTACT = '00000000000';
    private const APPLICATION_PREFIX = 'QA-RIDER-DRIVER-';
    private const FILE_ROOT = 'qa-fixtures/rider-driver-v1';

    /** @var array<int, array{business:string,municipality:list<string>,province:list<string>}> */
    private const CENTER_EXPECTATIONS = [
        1 => ['business' => 'Highland Cargo Link', 'municipality' => ['La Trinidad'], 'province' => ['Benguet']],
        2 => ['business' => 'MetroSwift Parcel Hub', 'municipality' => ['Quezon City'], 'province' => ['NCR', 'Metro Manila', 'National Capital Region', 'National Capital Region (NCR)', 'Metropolitan Manila']],
        3 => ['business' => 'Ilocos Route Cargo', 'municipality' => ['City of Laoag', 'Laoag City', 'Laoag'], 'province' => ['Ilocos Norte']],
        4 => ['business' => 'Cagayan Valley Transit Cargo', 'municipality' => ['Tuguegarao City', 'Tuguegarao'], 'province' => ['Cagayan']],
        5 => ['business' => 'Pampanga Express Dispatch', 'municipality' => ['City of San Fernando', 'San Fernando City'], 'province' => ['Pampanga']],
        6 => ['business' => 'Laguna Fulfill Logistics', 'municipality' => ['City of Santa Rosa', 'Santa Rosa City', 'Santa Rosa'], 'province' => ['Laguna']],
        7 => ['business' => 'Mindoro Portline Couriers', 'municipality' => ['City of Calapan', 'Calapan City', 'Calapan'], 'province' => ['Oriental Mindoro']],
        8 => ['business' => 'Bicol LastMile Transit', 'municipality' => ['City of Legazpi', 'Legazpi City', 'Legazpi'], 'province' => ['Albay']],
        9 => ['business' => 'Iloilo Fleetways Courier', 'municipality' => ['Pavia'], 'province' => ['Iloilo']],
        10 => ['business' => 'Negros Coast Cargo', 'municipality' => ['City of Silay', 'Silay City', 'Silay'], 'province' => ['Negros Occidental']],
        11 => ['business' => 'Cebu Citylink Delivery', 'municipality' => ['Minglanilla'], 'province' => ['Cebu']],
        12 => ['business' => 'Leyte Linkhaul Parcel', 'municipality' => ['Palo'], 'province' => ['Leyte']],
        13 => ['business' => 'Pagadian Swift Dispatch', 'municipality' => ['City of Pagadian', 'Pagadian City', 'Pagadian'], 'province' => ['Zamboanga del Sur']],
        14 => ['business' => 'Tagoloan Cargo Exchange', 'municipality' => ['Tagoloan'], 'province' => ['Misamis Oriental']],
        15 => ['business' => 'Digos Southern Route', 'municipality' => ['City of Digos', 'Digos City', 'Digos'], 'province' => ['Davao del Sur']],
        16 => ['business' => 'Koronadal Parcel Net', 'municipality' => ['City of Koronadal', 'Koronadal City', 'Koronadal'], 'province' => ['South Cotabato']],
        17 => ['business' => 'Agusan Freightway', 'municipality' => ['City of Cabadbaran', 'Cabadbaran City', 'Cabadbaran'], 'province' => ['Agusan del Norte']],
        18 => ['business' => 'Marawi Parcel Relay', 'municipality' => ['City of Marawi', 'Marawi City', 'Marawi'], 'province' => ['Lanao del Sur']],
    ];

    /** @var list<string> */
    private array $newFiles = [];

    /** @return list<array{id:int,first:string,middle:string,last:string,birthday:string,center:int,role:string,vehicle:string,email:string,contact_number:string,plate_number:string,drivers_license_number:string}> */
    public static function fixtures(): array
    {
        $raw = [
            ['John', 'M', 'Reyes', '1999-02-17', 'motorcycle'],
            ['Mark', 'A', 'Santos', '1991-08-04', 'closed_van'],
            ['Christian', 'P', 'Dela Cruz', '2001-04-25', 'motorcycle'],
            ['Joshua', 'L', 'Mendoza', '1993-10-18', 'van'],
            ['Kevin', 'R', 'Bautista', '1998-06-11', 'motorcycle'],
            ['Daniel', 'C', 'Garcia', '1989-01-29', 'truck'],
            ['Carlo', 'T', 'Ramirez', '2002-07-15', 'motorcycle'],
            ['Adrian', 'S', 'Flores', '1992-12-03', 'closed_van'],
            ['Patrick', 'J', 'Navarro', '1997-09-22', 'tricycle'],
            ['James', 'B', 'Villanueva', '1988-05-14', 'van'],
            ['Michael', 'G', 'Torres', '2000-03-08', 'motorcycle'],
            ['Ryan', 'N', 'Castillo', '1994-11-27', 'closed_van'],
            ['Joseph', 'F', 'Aquino', '2001-01-19', 'motorcycle'],
            ['Vincent', 'O', 'Ramos', '1990-06-02', 'van'],
            ['Francis', 'D', 'Mercado', '1996-08-13', 'motorcycle'],
            ['Angelo', 'E', 'Rivera', '1987-10-31', 'truck'],
            ['Jerome', 'L', 'Cruz', '2002-02-05', 'tricycle'],
            ['Bryan', 'V', 'Fernandez', '1991-04-17', 'van'],
            ['Eric', 'M', 'Gonzales', '1998-12-09', 'motorcycle'],
            ['Marvin', 'P', 'Lopez', '1989-07-23', 'closed_van'],
            ['Rafael', 'C', 'Morales', '2003-05-20', 'motorcycle'],
            ['Gabriel', 'D', 'Perez', '1993-09-06', 'truck'],
            ['Alvin', 'Q', 'Domingo', '1997-11-26', 'motorcycle'],
            ['Ronald', 'E', 'Santiago', '1986-03-12', 'van'],
            ['Nathaniel', 'B', 'Castro', '2000-10-07', 'tricycle'],
            ['Jeffrey', 'M', 'Salazar', '1992-01-16', 'closed_van'],
            ['Emmanuel', 'F', 'Diaz', '1999-08-28', 'motorcycle'],
            ['Christopher', 'S', 'Lim', '1990-02-09', 'truck'],
            ['Richard', 'N', 'Tan', '2001-06-03', 'motorcycle'],
            ['Dennis', 'C', 'Valdez', '1988-12-21', 'closed_van'],
            ['Marco', 'L', 'Antonio', '1996-04-30', 'motorcycle'],
            ['Anthony', 'R', 'Espinoza', '1991-07-07', 'van'],
            ['Kenneth', 'J', 'Soriano', '2002-09-01', 'motorcycle'],
            ['Roberto', 'A', 'Padilla', '1987-05-19', 'truck'],
            ['Luis', 'V', 'Hernandez', '1998-03-24', 'motorcycle'],
            ['Samuel', 'K', 'Gutierrez', '1989-11-15', 'closed_van'],
        ];

        return collect($raw)->values()->map(function (array $row, int $index): array {
            $number = $index + 1;
            $vehicle = $row[4];

            return [
                'id' => $number,
                'first' => $row[0],
                'middle' => $row[1],
                'last' => $row[2],
                'birthday' => $row[3],
                'center' => intdiv($index, 2) + 1,
                'role' => RiderVehicleType::isLocalBarangay($vehicle) ? 'LOCAL' : 'INTER-HUB',
                'vehicle' => $vehicle,
                'email' => Str::lower($row[0]).'@likhae.online',
                'contact_number' => '099900100'.sprintf('%02d', $number),
                'plate_number' => sprintf('QA-PLATE-%04d', $number),
                'drivers_license_number' => sprintf('QA-DL-%04d', $number),
            ];
        })->all();
    }

    /** @throws RuntimeException */
    public static function assertSafeTarget(string $environment, string $database, bool $allowProductionFixtures = false): void
    {
        $environment = strtolower(trim($environment));

        if ($environment === 'production') {
            if (! $allowProductionFixtures) {
                throw new RuntimeException('QaRiderDriverSeeder requires an explicit production opt-in.');
            }

            if (strtolower(trim($database)) !== 'likhae') {
                throw new RuntimeException('Production fixture seeding is restricted to the exact production database name likhae.');
            }

            return;
        }

        if (! in_array($environment, ['testing', 'qa'], true)) {
            throw new RuntimeException('QaRiderDriverSeeder is restricted to APP_ENV=testing or APP_ENV=qa unless production is explicitly opted into.');
        }

        if ($database !== ':memory:' && preg_match('/(?:^|[_-])(test|testing|qa)(?:$|[_-])/i', trim($database)) !== 1) {
            throw new RuntimeException('QaRiderDriverSeeder requires a database name containing a test or QA marker.');
        }
    }

    public function run(): void
    {
        $environment = (string) app()->environment();
        $allowProductionFixtures = filter_var(
            (string) env('QA_RIDER_DRIVER_ALLOW_PRODUCTION', 'false'),
            FILTER_VALIDATE_BOOLEAN,
        );

        if (! in_array(strtolower(trim($environment)), ['testing', 'qa'], true)
            && ! (strtolower(trim($environment)) === 'production' && $allowProductionFixtures)) {
            self::assertSafeTarget($environment, '', $allowProductionFixtures);
        }

        // Read the effective database name after the environment guard. This also
        // protects against DB_URL overriding a harmless-looking DB_DATABASE value.
        self::assertSafeTarget(
            $environment,
            (string) DB::connection()->getDatabaseName(),
            $allowProductionFixtures,
        );

        $fixtureRows = $this->resolveFixtureCenters();
        $this->preflightConflicts($fixtureRows);
        $this->newFiles = [];

        try {
            $result = DB::transaction(function () use ($fixtureRows): array {
                $actor = $this->fixtureActor();
                $counts = ['created' => 0, 'updated' => 0];

                foreach ($fixtureRows as $fixture) {
                    $wasExisting = User::query()->where('email', $this->emailFor($fixture))->exists();
                    $this->seedOne($fixture, $actor);
                    $counts[$wasExisting ? 'updated' : 'created']++;
                }

                return $counts;
            });
        } catch (Throwable $exception) {
            foreach ($this->newFiles as $diskAndPath) {
                [$disk, $path] = explode('|', $diskAndPath, 2);
                Storage::disk($disk)->delete($path);
            }

            throw $exception;
        }

        $this->command?->info(sprintf(
            'QA rider/driver fixture complete: %d personnel created, %d updated; no credentials were logged.',
            $result['created'],
            $result['updated'],
        ));
    }

    /** @return list<array<string, mixed>> */
    private function resolveFixtureCenters(): array
    {
        $expectedCodes = collect(range(1, 18))
            ->map(fn (int $number): string => sprintf('QA-LOGISTICS-%03d', $number))
            ->all();
        $centers = LogisticsCenter::query()
            ->with(['address', 'owner'])
            ->whereIn('code', $expectedCodes)
            ->get()
            ->keyBy('code');
        $missing = array_values(array_diff($expectedCodes, $centers->keys()->all()));

        if ($missing !== []) {
            throw new RuntimeException('Missing required QA logistics centers: '.implode(', ', $missing));
        }

        $slots = [];

        return collect(self::fixtures())->map(function (array $fixture) use ($centers, &$slots): array {
            $code = sprintf('QA-LOGISTICS-%03d', $fixture['center']);
            /** @var LogisticsCenter $center */
            $center = $centers->get($code);

            if ($center->status !== 'ACTIVE' || ! $center->approved_by_user_id || ! $center->approved_at) {
                throw new RuntimeException("Required center {$code} is not approved and active.");
            }
            if (! $center->owner || $center->owner->account_type !== User::TYPE_LOGISTICS) {
                throw new RuntimeException("Required center {$code} has no valid logistics owner.");
            }
            $expected = self::CENTER_EXPECTATIONS[$fixture['center']];
            if (strcasecmp(trim((string) $center->business_name), $expected['business']) !== 0) {
                throw new RuntimeException("Center {$code} is not the expected business {$expected['business']}.");
            }

            $address = $this->canonicalCenterAddress($center);
            if (! $this->geoLabelMatches((string) $address['municipality_name'], $expected['municipality'])
                || ! $this->geoLabelMatches((string) $address['province_name'], $expected['province'])) {
                throw new RuntimeException("Center {$code} address does not match its expected city and province.");
            }
            $slot = $slots[$code] ?? 0;
            $slots[$code] = $slot + 1;
            $barangays = $address['barangays'];
            $barangay = $barangays[$slot % count($barangays)];

            return [
                ...$fixture,
                'center_code' => $code,
                'center_id' => (int) $center->id,
                'center_name' => (string) $center->business_name,
                'address' => [
                    ...$address,
                    'barangay_code' => (string) $barangay['code'],
                    'barangay_name' => (string) $barangay['name'],
                ],
            ];
        })->all();
    }

    /** @return array<string, mixed> */
    private function canonicalCenterAddress(LogisticsCenter $center): array
    {
        $source = $center->address;
        if (! $source
            || blank($source->province_code)
            || blank($source->province_name)
            || blank($source->municipality_code)
            || blank($source->municipality_name)) {
            throw new RuntimeException("Center {$center->code} has no complete province and municipality address.");
        }

        $canonical = PhilippineAddressController::canonicalMunicipalityFor(
            (string) $source->province_code,
            (string) $source->province_name,
            (string) $source->municipality_code,
            (string) $source->municipality_name,
        );
        if (! $canonical) {
            throw new RuntimeException("Center {$center->code} could not be resolved from the configured PSGC reference data.");
        }

        $regionName = PhilippineAddressController::regionNameForCode((string) $canonical['region_code']);
        $barangays = collect(PhilippineAddressController::barangayRecordsForMunicipality((string) $canonical['municipality_code']))
            ->filter(fn ($row): bool => is_array($row)
                && filled($row['code'] ?? null)
                && filled($row['name'] ?? null))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        if (! $regionName || $barangays === []) {
            throw new RuntimeException("Center {$center->code} is missing canonical region or barangay reference data.");
        }

        $postalCode = trim((string) $source->postal_code);
        if ($postalCode === '') {
            $postalCode = PhilippineAddressController::expectedPostalCodeFor(
                (string) $canonical['province_code'],
                (string) $canonical['municipality_code'],
                (string) $canonical['province_name'],
                (string) $canonical['municipality_name'],
            ) ?? '';
        }
        if (preg_match('/^\d{4}$/', $postalCode) !== 1) {
            throw new RuntimeException("Center {$center->code} has no usable four-digit postal code.");
        }

        return [
            'region_code' => (string) $canonical['region_code'],
            'region_name' => $regionName,
            'province_code' => (string) $canonical['province_code'],
            'province_name' => (string) $canonical['province_name'],
            'municipality_code' => (string) $canonical['municipality_code'],
            'municipality_name' => (string) $canonical['municipality_name'],
            'postal_code' => $postalCode,
            'barangays' => $barangays,
        ];
    }

    /** @param list<array<string, mixed>> $fixtureRows */
    private function preflightConflicts(array $fixtureRows): void
    {
        $applicationNumbers = [];
        foreach ($fixtureRows as $fixture) {
            $email = $this->emailFor($fixture);
            $applicationNumber = $this->applicationNumber($fixture);
            $applicationNumbers[] = $applicationNumber;
            $user = User::query()->where('email', $email)->first();
            $application = RegistrationApplication::query()->where('application_number', $applicationNumber)->first();

            if ($user && (! $application
                || (int) $application->user_id !== (int) $user->id
                || ! str_contains((string) $application->decision_notes, self::FIXTURE_MARKER)
                || $user->account_type !== User::TYPE_RIDER)) {
                throw new RuntimeException("Email {$email} already belongs to a non-fixture account; no account was changed.");
            }
            if ($application && (! $user
                || (int) $application->user_id !== (int) $user->id
                || ! str_contains((string) $application->decision_notes, self::FIXTURE_MARKER))) {
                throw new RuntimeException("Application {$applicationNumber} conflicts with an unmarked record.");
            }

            $phone = $this->phoneFor($fixture);
            $phoneQuery = User::query()->where('contact_number', $phone);
            if ($user) {
                $phoneQuery->where('id', '<>', $user->id);
            }
            if ($phoneQuery->exists()) {
                throw new RuntimeException("Synthetic contact number {$phone} is already assigned to another account.");
            }

            $profileQuery = RiderProfile::query()->where(function ($query) use ($fixture): void {
                $query->where('plate_number', $this->plateFor($fixture))
                    ->orWhere('drivers_license_number', $this->licenseFor($fixture));
            });
            if ($user) {
                $profileQuery->where('user_id', '<>', $user->id);
            }
            if ($profileQuery->exists()) {
                throw new RuntimeException("Synthetic vehicle identifiers for {$email} conflict with another rider profile.");
            }

            $applicationDataQuery = RiderApplicationData::query()->where(function ($query) use ($fixture): void {
                $query->where('plate_number', $this->plateFor($fixture))
                    ->orWhere('drivers_license_number', $this->licenseFor($fixture));
            });
            if ($application) {
                $applicationDataQuery->where('registration_application_id', '<>', $application->id);
            }
            if ($applicationDataQuery->exists()) {
                throw new RuntimeException("Synthetic vehicle identifiers for {$email} conflict with another rider application.");
            }
        }

        if (count(array_unique($applicationNumbers)) !== 36) {
            throw new RuntimeException('The QA fixture application numbers are not unique.');
        }

        $actor = User::query()->where('email', self::ACTOR_EMAIL)->first();
        if ($actor && ! $this->isFixtureActor($actor)) {
            throw new RuntimeException('The reserved QA approval actor email belongs to an unrelated account.');
        }
        if (! $actor && User::query()->where('contact_number', self::ACTOR_CONTACT)->exists()) {
            throw new RuntimeException('The reserved QA approval actor contact number is already in use.');
        }
    }

    private function fixtureActor(): User
    {
        $actor = User::query()->where('email', self::ACTOR_EMAIL)->first();
        if ($actor) {
            if (! $this->isFixtureActor($actor)) {
                throw new RuntimeException('The reserved QA approval actor email belongs to an unrelated account.');
            }

            return $actor;
        }

        return User::create([
            'account_type' => User::TYPE_BUYER,
            'first_name' => 'QA Fixture',
            'last_name' => 'Seeder',
            'sex' => 'PREFER_NOT_TO_SAY',
            'email' => self::ACTOR_EMAIL,
            'contact_number' => self::ACTOR_CONTACT,
            'birthday' => '2000-01-01',
            'password' => Hash::make(Str::random(64)),
            'status' => User::STATUS_DEACTIVATED,
        ]);
    }

    private function isFixtureActor(User $actor): bool
    {
        return $actor->account_type === User::TYPE_BUYER
            && $actor->first_name === 'QA Fixture'
            && $actor->last_name === 'Seeder'
            && $actor->contact_number === self::ACTOR_CONTACT
            && $actor->status === User::STATUS_DEACTIVATED
            && ! $actor->email_verified_at
            && ! $actor->registrationApplications()->exists();
    }

    /** @param array<string, mixed> $fixture */
    private function seedOne(array $fixture, User $actor): void
    {
        $birthday = Carbon::parse($fixture['birthday']);
        if ($birthday->isFuture() || $birthday->age < 18) {
            throw new RuntimeException("Fixture {$fixture['id']} is not an adult as of today.");
        }

        $email = $this->emailFor($fixture);
        $avatarPath = self::FILE_ROOT.'/avatars/'.sprintf('rider-%04d.svg', $fixture['id']);
        $this->ensureFixtureFile('public', $avatarPath, $this->avatarSvg((int) $fixture['id']));

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->fill([
            'account_type' => User::TYPE_RIDER,
            'first_name' => $fixture['first'],
            'middle_initial' => $fixture['middle'],
            'last_name' => $fixture['last'],
            'name_extension' => null,
            'sex' => 'MALE',
            'contact_number' => $this->phoneFor($fixture),
            'birthday' => $fixture['birthday'],
            'email_verified_at' => $user->email_verified_at ?: now(),
            'status' => User::STATUS_ACTIVE,
            'profile_photo_path' => $avatarPath,
        ]);
        $user->password = Hash::make('Password1');
        $user->save();

        $user->addresses()
            ->where(fn ($query) => $query->whereNull('label')->orWhere('label', '<>', 'QA Rider Home'))
            ->update(['is_default' => false]);
        $user->addresses()->updateOrCreate(
            ['label' => 'QA Rider Home'],
            [
                'recipient_name' => $user->name,
                'contact_number' => $user->contact_number,
                'region_code' => $fixture['address']['region_code'],
                'region_name' => $fixture['address']['region_name'],
                'province_code' => $fixture['address']['province_code'],
                'province_name' => $fixture['address']['province_name'],
                'municipality_code' => $fixture['address']['municipality_code'],
                'municipality_name' => $fixture['address']['municipality_name'],
                'barangay_code' => $fixture['address']['barangay_code'],
                'barangay_name' => $fixture['address']['barangay_name'],
                'postal_code' => $fixture['address']['postal_code'],
                'house_number' => sprintf('Unit QA-%04d-A', $fixture['id']),
                'street_address' => sprintf('Purok %d, %s', (($fixture['id'] - 1) % 5) + 1, $fixture['role'] === 'LOCAL' ? 'Parcel Access Lane' : 'Hub Transfer Road'),
                'landmark' => sprintf('Building %02d, QA Logistics Village; near %s.', $fixture['id'], $fixture['center_name']),
                'is_default' => true,
            ],
        );

        $applicationNumber = $this->applicationNumber($fixture);
        $application = RegistrationApplication::query()->firstOrNew(['application_number' => $applicationNumber]);
        $application->fill([
            'user_id' => $user->id,
            'status' => RegistrationApplication::STATUS_APPROVED,
            'reviewed_by_user_id' => $actor->id,
            'submitted_at' => $application->submitted_at ?: now(),
            'reviewed_at' => $application->reviewed_at ?: now(),
            'decision_notes' => self::FIXTURE_MARKER.'; synthetic QA fixture approval by '.$actor->email.'; sample documents only, not identity-verified; no logistics owner impersonated.',
            'rejection_reason' => null,
        ]);
        $application->save();

        RiderApplicationData::query()->updateOrCreate(
            ['registration_application_id' => $application->id],
            [
                'target_logistics_center_id' => $fixture['center_id'],
                'vehicle_type' => $fixture['vehicle'],
                'plate_number' => $this->plateFor($fixture),
                'drivers_license_number' => $this->licenseFor($fixture),
            ],
        );

        foreach ([
            'VALID_ID' => 'government-id',
            'VEHICLE_OR_CR' => 'vehicle-or-cr',
            'DRIVERS_LICENSE' => 'drivers-license',
        ] as $documentType => $documentSlug) {
            $relativePath = 'registration/rider/'.$applicationNumber.'/SAMPLE-TEST-ONLY-'.$documentSlug.'.pdf';
            $this->ensureFixtureFile('registrations', $relativePath, $this->placeholderPdf($documentType));
            ApplicationDocument::query()->updateOrCreate(
                ['registration_application_id' => $application->id, 'document_type' => $documentType],
                [
                    'file_path' => $relativePath,
                    'original_name' => 'SAMPLE-TEST-ONLY-'.$documentSlug.'.pdf',
                    'mime_type' => 'application/pdf',
                    'verification_status' => 'VERIFIED',
                    'verified_by_user_id' => $actor->id,
                    'verified_at' => now(),
                    'rejection_reason' => null,
                ],
            );
        }

        $profile = RiderProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'logistics_center_id' => $fixture['center_id'],
                'vehicle_type' => $fixture['vehicle'],
                'plate_number' => $this->plateFor($fixture),
                'drivers_license_number' => $this->licenseFor($fixture),
                'status' => 'ACTIVE',
                'is_online' => false,
                'approved_by_user_id' => $actor->id,
                'approved_at' => $application->reviewed_at,
            ],
        );

        AuditLog::query()->updateOrCreate(
            [
                'actor_user_id' => $actor->id,
                'event' => 'qa_fixture.rider_driver.approved',
                'auditable_type' => RegistrationApplication::class,
                'auditable_id' => $application->id,
            ],
            [
                'old_values' => ['status' => RegistrationApplication::STATUS_PENDING],
                'new_values' => [
                    'status' => RegistrationApplication::STATUS_APPROVED,
                    'account_status' => User::STATUS_ACTIVE,
                    'membership_status' => $profile->status,
                    'logistics_center_id' => (int) $fixture['center_id'],
                    'vehicle_role' => $fixture['role'],
                    'fixture_marker' => self::FIXTURE_MARKER,
                ],
                'created_at' => $application->reviewed_at,
            ],
        );
    }

    private function ensureFixtureFile(string $disk, string $path, string $contents): void
    {
        $storage = Storage::disk($disk);
        if ($storage->exists($path)) {
            if (! hash_equals(hash('sha256', $contents), hash('sha256', (string) $storage->get($path)))) {
                throw new RuntimeException("Fixture file path already exists with different content: {$path}");
            }

            return;
        }

        if (! $storage->put($path, $contents)) {
            throw new RuntimeException("Could not write QA fixture file: {$path}");
        }

        $this->newFiles[] = $disk.'|'.$path;
    }

    private function placeholderPdf(string $documentType): string
    {
        $label = match ($documentType) {
            'VALID_ID' => 'GOVERNMENT ID SAMPLE',
            'VEHICLE_OR_CR' => 'VEHICLE OR / CR SAMPLE',
            default => 'DRIVERS LICENSE SAMPLE',
        };
        $lines = [
            'SAMPLE - TEST ONLY',
            $label,
            'NOT A VALID GOVERNMENT DOCUMENT',
            'Synthetic QA fixture; no identity details or legal credentials.',
            'Fixture approval only; identity and document authenticity were not verified.',
        ];
        $commands = "BT\n/F1 25 Tf\n0.72 0.05 0.05 rg\n72 700 Td\n";
        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $commands .= "0 -42 Td\n/F1 ".($index === 1 ? '18' : '12')." Tf\n";
                if ($index === 2) {
                    $commands .= "0.2 0.2 0.2 rg\n";
                }
            }
            $commands .= '('.$this->escapePdfText($line).") Tj\n";
        }
        $commands .= "ET";

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
            '<< /Length '.strlen($commands).">>\nstream\n{$commands}\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";
    }

    private function escapePdfText(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    /** @param list<string> $expectedLabels */
    private function geoLabelMatches(string $actual, array $expectedLabels): bool
    {
        $normalize = fn (string $value): string => Str::of($value)->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
        $actual = $normalize($actual);

        return collect($expectedLabels)->contains(fn (string $expected): bool => $normalize($expected) === $actual);
    }

    private function avatarSvg(int $number): string
    {
        $hue = ($number * 47) % 360;
        return '<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 256 256">'
            .'<rect width="256" height="256" rx="128" fill="hsl('.$hue.',52%,82%)"/>'
            .'<circle cx="128" cy="94" r="43" fill="#fff" opacity=".94"/>'
            .'<path d="M48 226c5-48 35-75 80-75s75 27 80 75" fill="#fff" opacity=".94"/>'
            .'<text x="128" y="245" text-anchor="middle" font-family="Arial,sans-serif" font-size="15" font-weight="700" fill="#25434a">QA SAMPLE</text>'
            .'</svg>';
    }

    /** @param array<string, mixed> $fixture */
    private function emailFor(array $fixture): string
    {
        return $fixture['email'];
    }

    /** @param array<string, mixed> $fixture */
    private function phoneFor(array $fixture): string
    {
        return $fixture['contact_number'];
    }

    /** @param array<string, mixed> $fixture */
    private function plateFor(array $fixture): string
    {
        return $fixture['plate_number'];
    }

    /** @param array<string, mixed> $fixture */
    private function licenseFor(array $fixture): string
    {
        return $fixture['drivers_license_number'];
    }

    /** @param array<string, mixed> $fixture */
    private function applicationNumber(array $fixture): string
    {
        return self::APPLICATION_PREFIX.sprintf('%04d', $fixture['id']);
    }
}
