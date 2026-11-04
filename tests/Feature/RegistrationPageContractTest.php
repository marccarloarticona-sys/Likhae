<?php

namespace Tests\Feature;

use App\Models\Buyer\Address;
use App\Models\Logistics\LogisticsCenter;
use App\Models\User;
use App\Support\RiderVehicleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationPageContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_registration_page_loads_with_its_id_upload(): void
    {
        $this->get(route('register', ['role' => 'buyer']))
            ->assertOk()
            ->assertSee('Step 1 of 5')
            ->assertSee('data-profile-progress-step', false)
            ->assertSee('id="valid_id"', false)
            ->assertSee('Upload ID');
    }

    public function test_seller_and_logistics_pages_expose_the_fields_required_by_registration_validation(): void
    {
        $this->get(route('register', ['role' => 'seller']))
            ->assertOk()
            ->assertSee('name="business_name"', false)
            ->assertSee('name="line_of_business"', false)
            ->assertSee('data-registration-business-uploads', false)
            ->assertSee('id="valid_id"', false)
            ->assertSee('name="business_permit"', false)
            ->assertSee('name="postal_code"', false)
            ->assertSee('data-review-registration-policies', false)
            ->assertSee('data-password-toggle="password"', false)
            ->assertSee('data-password-toggle="password_confirmation"', false)
            ->assertDontSee('name="seller_type"', false)
            ->assertDontSee('name="tin"', false);

        $this->get(route('register', ['role' => 'logistics']))
            ->assertOk()
            ->assertSee('name="business_name"', false)
            ->assertSee('data-registration-business-uploads', false)
            ->assertSee('id="valid_id"', false)
            ->assertSee('name="business_permit"', false)
            ->assertDontSee('name="business_registration_number"', false)
            ->assertDontSee('name="tin"', false);
    }

    public function test_rider_registration_uses_the_existing_local_and_interhub_vehicle_options(): void
    {
        $response = $this->get(route('register', ['role' => 'rider']))->assertOk();

        foreach (RiderVehicleType::registrationOptions() as $value => $label) {
            $response->assertSee('value="'.$value.'"', false)
                ->assertSee($label);
        }

        $response->assertSee('name="or_cr"', false)
            ->assertSee('name="drivers_license"', false)
            ->assertSee('name="drivers_license_number"', false)
            ->assertSee('name="target_logistics_center_id"', false)
            ->assertSee('data-registration-address-pin', false)
            ->assertSee('data-address-pin-required="false"', false);

        $this->assertMatchesRegularExpression(
            '/data-registration-address-pin\s+hidden/',
            $response->getContent(),
        );
    }

    public function test_rider_registration_includes_center_municipality_codes_for_local_filtering(): void
    {
        $admin = User::factory()->create(['account_type' => User::TYPE_ADMIN]);
        $localCenter = $this->logisticsCenter($admin, 'Pagsanjan Center', '0403418000', true);
        $this->logisticsCenter($admin, 'Other Municipality Center', '0403424000', true);
        $this->logisticsCenter($admin, 'Unapproved Center', '0403418000', false);

        $this->get(route('register', ['role' => 'rider']))
            ->assertOk()
            ->assertSee('data-logistics-center-option="true"', false)
            ->assertSee('data-province-code="0403400000"', false)
            ->assertSee('data-municipality-code="0403418000"', false)
            ->assertSee('Pagsanjan Center')
            ->assertSee('Other Municipality Center')
            ->assertDontSee('Unapproved Center')
            ->assertSee('data-logistics-center-status aria-live="polite"', false);

        $this->assertNotNull($localCenter->fresh());
    }

    public function test_underage_registration_returns_a_specific_minimum_age_error(): void
    {
        $response = $this->post(route('register.store'), [
            'account_type' => 'buyer',
            'birthday' => now()->subYears(16)->toDateString(),
            'age' => '16',
        ]);

        $response->assertRedirect()->assertSessionHasErrors(['birthday', 'age']);
        $response->assertSessionDoesntHaveErrors(['postal_code']);
        $this->assertSame(
            'You must be at least 18 years old to register.',
            session('errors')->first('birthday'),
        );
    }

    public function test_seller_registration_does_not_require_business_fields_missing_from_the_pdf_spec(): void
    {
        $response = $this->post(route('register.store'), ['account_type' => 'seller']);

        $response->assertRedirect()->assertSessionHasErrors(['business_name', 'line_of_business', 'business_permit']);
        $response->assertSessionDoesntHaveErrors([
            'seller_type',
            'tin',
            'business_registration_number',
            'dti_certificate',
            'sec_certificate',
            'bir_form_2303',
            'postal_code',
        ]);
    }

    private function logisticsCenter(User $admin, string $name, string $municipalityCode, bool $approved): LogisticsCenter
    {
        $owner = User::factory()->create(['account_type' => User::TYPE_LOGISTICS]);
        $address = Address::query()->create([
            'user_id' => $owner->id,
            'recipient_name' => $owner->name,
            'contact_number' => $owner->contact_number,
            'province_code' => '0403400000',
            'province_name' => 'Laguna',
            'municipality_code' => $municipalityCode,
            'municipality_name' => $name,
            'barangay_code' => $municipalityCode.'001',
            'barangay_name' => 'Barangay One',
            'street_address' => 'Center Street',
            'is_default' => true,
        ]);

        return LogisticsCenter::query()->create([
            'owner_user_id' => $owner->id,
            'address_id' => $address->id,
            'code' => 'LC-'.str()->slug($name),
            'business_name' => $name,
            'status' => 'ACTIVE',
            'approved_by_user_id' => $approved ? $admin->id : null,
            'approved_at' => $approved ? now() : null,
        ]);
    }
}
