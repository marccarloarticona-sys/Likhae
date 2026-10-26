<?php

namespace App\Http\Controllers\Communication;

use App\Http\Controllers\Controller;
use App\Models\Communication\MessagingFaq;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MessagingFaqController extends Controller
{
    public function sellerIndex(Request $request): View
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($request->user()->isAccountType(User::TYPE_SELLER) && $seller?->status === 'ACTIVE', 403);

        $faqs = MessagingFaq::query()->where('owner_type', MessagingFaq::OWNER_SELLER)
            ->where('seller_profile_id', $seller->id)->with(['product:id,name', 'productVariant:id,product_id,sku'])
            ->orderBy('sort_order')->orderBy('id')->paginate(30)->withQueryString();
        $products = Product::query()->where('seller_profile_id', $seller->id)->orderBy('name')->limit(200)->get(['id', 'name']);
        $variants = ProductVariant::query()->whereIn('product_id', $products->pluck('id'))
            ->with('product:id,name')->orderBy('product_id')->orderBy('sku')->limit(1000)->get(['id', 'product_id', 'sku']);

        return view('seller.messaging-faqs', compact('faqs', 'products', 'variants'));
    }

    public function storeSeller(Request $request): RedirectResponse
    {
        $seller = $this->assertSeller($request->user());
        $data = $this->validateFaq($request, $seller);
        $this->assertNoDuplicate(MessagingFaq::OWNER_SELLER, (int) $seller->id, $data);
        MessagingFaq::query()->create([
            ...$data,
            'owner_type' => MessagingFaq::OWNER_SELLER,
            'seller_profile_id' => $seller->id,
            'created_by_user_id' => $request->user()->id,
        ]);

        return back()->with('status', 'Suggested answer saved.');
    }

    public function updateSeller(Request $request, MessagingFaq $faq): RedirectResponse
    {
        $seller = $this->assertSeller($request->user());
        $owned = MessagingFaq::query()->whereKey($faq->id)->where('owner_type', MessagingFaq::OWNER_SELLER)
            ->where('seller_profile_id', $seller->id)->firstOrFail();
        $data = $this->validateFaq($request, $seller);
        $this->assertNoDuplicate(MessagingFaq::OWNER_SELLER, (int) $seller->id, $data, (int) $owned->id);
        $owned->update($data);

        return back()->with('status', 'Suggested answer updated.');
    }

    public function archiveSeller(Request $request, MessagingFaq $faq): RedirectResponse
    {
        $seller = $this->assertSeller($request->user());
        $owned = MessagingFaq::query()->whereKey($faq->id)->where('owner_type', MessagingFaq::OWNER_SELLER)
            ->where('seller_profile_id', $seller->id)->firstOrFail();
        $owned->update(['is_active' => false]);

        return back()->with('status', 'Suggested answer archived.');
    }

    public function adminIndex(Request $request): View
    {
        abort_unless($request->user()->isAccountType(User::TYPE_ADMIN), 403);
        $search = trim((string) $request->query('q', ''));
        $faqs = MessagingFaq::query()->where('owner_type', MessagingFaq::OWNER_ADMIN)->whereNull('seller_profile_id')
            ->when($search !== '', fn ($query) => $query->where(fn ($nested) => $nested
                ->where('question', 'like', '%'.$search.'%')->orWhere('answer', 'like', '%'.$search.'%')->orWhere('category', 'like', '%'.$search.'%')))
            ->orderBy('sort_order')->orderBy('id')->paginate(30)->withQueryString();

        return view('admin.messaging-faqs.index', compact('faqs', 'search'));
    }

    public function storeAdmin(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAccountType(User::TYPE_ADMIN), 403);
        $data = $this->validateFaq($request, null);
        $this->assertNoDuplicate(MessagingFaq::OWNER_ADMIN, null, $data);
        MessagingFaq::query()->create([
            ...$data,
            'owner_type' => MessagingFaq::OWNER_ADMIN,
            'seller_profile_id' => null,
            'created_by_user_id' => $request->user()->id,
            'product_id' => null,
            'product_variant_id' => null,
        ]);

        return back()->with('status', 'Support FAQ saved.');
    }

    public function updateAdmin(Request $request, MessagingFaq $faq): RedirectResponse
    {
        abort_unless($request->user()->isAccountType(User::TYPE_ADMIN), 403);
        $owned = MessagingFaq::query()->whereKey($faq->id)->where('owner_type', MessagingFaq::OWNER_ADMIN)->whereNull('seller_profile_id')->firstOrFail();
        $data = $this->validateFaq($request, null);
        $this->assertNoDuplicate(MessagingFaq::OWNER_ADMIN, null, $data, (int) $owned->id);
        $owned->update([...$data, 'product_id' => null, 'product_variant_id' => null]);

        return back()->with('status', 'Support FAQ updated.');
    }

    public function archiveAdmin(Request $request, MessagingFaq $faq): RedirectResponse
    {
        abort_unless($request->user()->isAccountType(User::TYPE_ADMIN), 403);
        MessagingFaq::query()->whereKey($faq->id)->where('owner_type', MessagingFaq::OWNER_ADMIN)->whereNull('seller_profile_id')
            ->firstOrFail()->update(['is_active' => false]);

        return back()->with('status', 'Support FAQ archived.');
    }

    private function assertSeller(User $user): SellerProfile
    {
        abort_unless($user->isAccountType(User::TYPE_SELLER), 403);
        $seller = $user->sellerProfile;
        abort_unless($seller?->status === 'ACTIVE', 403);

        return $seller;
    }

    /** @return array<string, mixed> */
    private function validateFaq(Request $request, ?SellerProfile $seller): array
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:60'],
            'question' => ['required', 'string', 'max:300'],
            'answer' => ['required', 'string', 'max:3000'],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'product_variant_id' => ['nullable', 'integer', 'min:1'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);
        $data['category'] = trim(strip_tags($data['category']));
        $data['question'] = trim(strip_tags($data['question']));
        $data['answer'] = trim(strip_tags($data['answer']));
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        if ($data['category'] === '' || $data['question'] === '' || $data['answer'] === '') {
            throw ValidationException::withMessages(['question' => 'Category, question, and answer are required.']);
        }

        if ($seller) {
            $productId = isset($data['product_id']) ? (int) $data['product_id'] : null;
            $variantId = isset($data['product_variant_id']) ? (int) $data['product_variant_id'] : null;
            if ($productId && ! Product::query()->whereKey($productId)->where('seller_profile_id', $seller->id)->exists()) {
                throw ValidationException::withMessages(['product_id' => 'Choose a product owned by your store.']);
            }
            if ($variantId && (! $productId || ! ProductVariant::query()->whereKey($variantId)->where('product_id', $productId)->exists())) {
                throw ValidationException::withMessages(['product_variant_id' => 'Choose a variant belonging to the selected product.']);
            }
            $data['product_id'] = $productId;
            $data['product_variant_id'] = $variantId;
        } else {
            $data['product_id'] = null;
            $data['product_variant_id'] = null;
        }

        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    private function assertNoDuplicate(string $ownerType, ?int $sellerProfileId, array $data, ?int $exceptId = null): void
    {
        $query = MessagingFaq::query()->where('owner_type', $ownerType)
            ->where('seller_profile_id', $sellerProfileId)
            ->whereRaw('LOWER(question) = ?', [mb_strtolower($data['question'])])
            ->where('is_active', true)
            ->where('product_id', $data['product_id'])
            ->where('product_variant_id', $data['product_variant_id']);
        if ($exceptId) {
            $query->where('id', '<>', $exceptId);
        }
        if ($query->exists() && ($data['is_active'] ?? true)) {
            throw ValidationException::withMessages(['question' => 'An active question with this wording already exists for the same context.']);
        }
    }
}
