<?php

namespace Tests\Unit;

use App\Models\Logistics\Shipment;
use App\Support\ParcelVolumeEstimator;
use PHPUnit\Framework\TestCase;

class ParcelVolumeEstimatorTest extends TestCase
{
    public function test_packed_dimensions_provide_a_liter_estimate(): void
    {
        $shipment = new Shipment([
            'parcel_length_cm' => '20.00',
            'parcel_width_cm' => '10.00',
            'parcel_height_cm' => '5.00',
        ]);

        $this->assertSame(1.0, ParcelVolumeEstimator::estimate($shipment));
    }

    public function test_measured_volume_takes_precedence_over_the_dimension_estimate(): void
    {
        $shipment = new Shipment([
            'parcel_volume_liters' => '2.250',
            'parcel_length_cm' => '20.00',
            'parcel_width_cm' => '10.00',
            'parcel_height_cm' => '5.00',
        ]);

        $this->assertSame(2.25, ParcelVolumeEstimator::estimate($shipment));
    }

    public function test_incomplete_or_nonpositive_dimensions_have_no_volume_estimate(): void
    {
        $this->assertNull(ParcelVolumeEstimator::estimate(new Shipment([
            'parcel_length_cm' => '20.00',
            'parcel_width_cm' => '10.00',
        ])));
        $this->assertNull(ParcelVolumeEstimator::estimate(new Shipment([
            'parcel_length_cm' => '20.00',
            'parcel_width_cm' => '10.00',
            'parcel_height_cm' => '0.00',
        ])));
    }
}
