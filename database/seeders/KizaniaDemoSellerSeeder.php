<?php

namespace Database\Seeders;

use App\Models\Auth\ApplicationDocument;
use App\Models\Auth\RegistrationApplication;
use App\Models\Buyer\Address;
use App\Models\Seller\Category;
use App\Models\Seller\SellerApplicationData;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Throwable;

class KizaniaDemoSellerSeeder extends Seeder
{
    private const EMAIL = 'kidzania@likhae.online';

    private const APPLICATION_NUMBER = 'REG-SEED-KIZANIA-20261028';

    private const DOCUMENT_PATH = 'registration/seller/REG-SEED-KIZANIA-20261028/valid_id/DEMO_PLACEHOLDER_NOT_VALID_ID.pdf';

    public function run(): void
    {
        if (User::query()->where('email', self::EMAIL)->exists()) {
            $this->command?->info('Kizania demo seller already exists; no changes made.');

            return;
        }

        $category = Category::query()
            ->whereNull('parent_id')
            ->where('name', 'Kids and Baby')
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->firstOrFail();

        try {
            DB::transaction(function () use ($category): void {
                $user = User::create([
                    'account_type' => User::TYPE_SELLER,
                    'first_name' => 'Kizania',
                    'last_name' => 'Demo Seller',
                    'sex' => 'PREFER_NOT_TO_SAY',
                    'email' => self::EMAIL,
                    'contact_number' => '0000000000',
                    'birthday' => '2000-01-01',
                    'password' => Hash::make('Password1'),
                    'status' => User::STATUS_PENDING,
                ]);

                $address = Address::create([
                    'user_id' => $user->id,
                    'label' => 'Primary',
                    'recipient_name' => $user->name,
                    'contact_number' => $user->contact_number,
                    'province_code' => 'LAG',
                    'province_name' => 'Laguna',
                    'municipality_code' => 'PILA',
                    'municipality_name' => 'Pila',
                    'barangay_code' => 'UNSPECIFIED',
                    'barangay_name' => 'Not provided',
                    'postal_code' => '4010',
                    'house_number' => 'N/A',
                    'street_address' => 'Address details not provided - demo seed',
                    'landmark' => 'Pila, Laguna',
                    'is_default' => true,
                ]);

                $application = RegistrationApplication::create([
                    'application_number' => self::APPLICATION_NUMBER,
                    'user_id' => $user->id,
                    'status' => RegistrationApplication::STATUS_PENDING,
                    'submitted_at' => now(),
                    'decision_notes' => 'DEMO SEED: profile fields marked as demo are placeholders. The attached identity document is not valid evidence; replace it and provide genuine legal details before approval.',
                ]);

                SellerApplicationData::create([
                    'registration_application_id' => $application->id,
                    'category_id' => $category->id,
                    'business_name' => 'Kizania',
                    'business_registration_number' => null,
                    'seller_type' => null,
                    'tin' => null,
                ]);

                SellerProfile::create([
                    'user_id' => $user->id,
                    'primary_category_id' => $category->id,
                    'business_address_id' => $address->id,
                    'business_name' => 'Kizania',
                    'business_registration_number' => null,
                    'seller_type' => null,
                    'tin' => null,
                    'location' => 'Pila, Laguna',
                    'status' => 'PENDING',
                ]);

                Storage::disk('registrations')->put(self::DOCUMENT_PATH, $this->placeholderPdf());

                ApplicationDocument::create([
                    'registration_application_id' => $application->id,
                    'document_type' => 'VALID_ID',
                    'file_path' => self::DOCUMENT_PATH,
                    'original_name' => 'DEMO_PLACEHOLDER_NOT_VALID_ID.pdf',
                    'mime_type' => 'application/pdf',
                    'verification_status' => 'REJECTED',
                    'rejection_reason' => 'Demo placeholder only. This is not an identity document and cannot be accepted for verification. Upload a genuine, current ID.',
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk('registrations')->delete(self::DOCUMENT_PATH);

            throw $exception;
        }

        $this->command?->info('Created pending Kizania demo seller and clearly invalid identity-document placeholder.');
    }

    private function placeholderPdf(): string
    {
        $stream = "BT\n/F1 24 Tf\n0.75 0 0 rg\n72 700 Td\n(DEMO PLACEHOLDER ONLY) Tj\n0 -44 Td\n/F1 17 Tf\n(NOT A VALID IDENTIFICATION DOCUMENT) Tj\n0 -46 Td\n/F1 12 Tf\n(This file contains no identity data or legal credentials.) Tj\n0 -24 Td\n(Applicant: Kizania Demo Seller) Tj\n0 -36 Td\n(Upload a genuine current ID before application approval.) Tj\nET";

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";
    }
}
