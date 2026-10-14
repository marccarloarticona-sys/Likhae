<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Protection\FragileProtectionRule;
use App\Models\Seller\Category;
use App\Models\Seller\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminProtectionRuleController extends Controller
{
    public function index(): View
    {
        $ready = Schema::hasTable('fragile_protection_rules');

        return view('Admin.protection-rules.index', [
            'ready' => $ready,
            'rules' => $ready ? FragileProtectionRule::query()->with(['category', 'subcategory', 'product'])->latest()->paginate(20) : collect(),
            'categories' => Category::query()->whereNull('parent_id')->where('is_active', true)->orderBy('name')->with('children')->get(),
            'products' => Product::query()->where('status', 'ACTIVE')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(Schema::hasTable('fragile_protection_rules'), 503, 'Protection rules are pending additive migration approval.');
        $rule = $this->validateRule($request);
        $rule['active'] = $request->boolean('active');
        $rule['created_by_user_id'] = $request->user()->id;
        FragileProtectionRule::query()->create($rule);

        return back()->with('status', 'Protection rule saved. It will charge only while active and within its effective dates and price band.');
    }

    public function update(Request $request, FragileProtectionRule $protectionRule): RedirectResponse
    {
        abort_unless(Schema::hasTable('fragile_protection_rules'), 503, 'Protection rules are pending additive migration approval.');
        $rule = $this->validateRule($request);
        $rule['active'] = $request->boolean('active');
        $protectionRule->update($rule);

        return back()->with('status', 'Protection rule updated. Existing orders retain their snapshots.');
    }

    public function toggle(FragileProtectionRule $protectionRule): RedirectResponse
    {
        abort_unless(Schema::hasTable('fragile_protection_rules'), 503, 'Protection rules are pending additive migration approval.');
        $protectionRule->update(['active' => ! $protectionRule->active]);

        return back()->with('status', $protectionRule->active ? 'Protection rule activated.' : 'Protection rule deactivated.');
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(): array
    {
        return [
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNull('parent_id')],
            'subcategory_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereNotNull('parent_id')],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')],
            'risk_level' => ['nullable', 'string', 'max:32'],
            'calculation_type' => ['required', Rule::in(['PERCENTAGE', 'FLAT', 'HYBRID'])],
            'scope' => ['required', Rule::in(['PER_UNIT', 'PER_LINE'])],
            'percentage_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'flat_amount' => ['nullable', 'numeric', 'min:0'],
            'minimum_fee' => ['nullable', 'numeric', 'min:0'],
            'maximum_fee' => ['nullable', 'numeric', 'min:0', 'gte:minimum_fee'],
            'minimum_product_price' => ['nullable', 'numeric', 'min:0'],
            'maximum_product_price' => ['nullable', 'numeric', 'min:0', 'gte:minimum_product_price'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }

    /** @return array<string, mixed> */
    private function validateRule(Request $request): array
    {
        $validator = Validator::make($request->all(), $this->rules());
        $validator->after(function ($validator) use ($request): void {
            $targets = ['category_id', 'subcategory_id', 'product_id'];
            $targetCount = collect($targets)->filter(fn (string $field): bool => filled($request->input($field)))->count();
            if ($targetCount !== 1) {
                $validator->errors()->add('category_id', 'Choose exactly one target: a category, subcategory, or product.');
            }

            $type = strtoupper((string) $request->input('calculation_type'));
            $percentage = $request->input('percentage_rate');
            $flatAmount = $request->input('flat_amount');
            $hasPercentage = is_numeric($percentage) && (float) $percentage > 0;
            $hasFlatAmount = is_numeric($flatAmount) && (float) $flatAmount > 0;

            if ($type === 'PERCENTAGE' && ! $hasPercentage) {
                $validator->errors()->add('percentage_rate', 'Enter a percentage greater than zero for a percentage rule.');
            } elseif ($type === 'FLAT' && ! $hasFlatAmount) {
                $validator->errors()->add('flat_amount', 'Enter a flat amount greater than zero for a flat rule.');
            } elseif ($type === 'HYBRID' && ! $hasPercentage && ! $hasFlatAmount) {
                $validator->errors()->add('percentage_rate', 'A hybrid rule needs a percentage or a flat amount greater than zero.');
            }
        });

        return $validator->validate();
    }
}
