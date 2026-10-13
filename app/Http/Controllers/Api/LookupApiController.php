<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Seller\Category;
use Illuminate\Http\JsonResponse;

class LookupApiController extends Controller
{
    public function categories(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => Category::query()
                ->where('is_active', true)
                ->whereNull('archived_at')
                ->orderBy('name')
                ->get(['id', 'parent_id', 'name', 'slug', 'description'])
                ->values(),
        ]);
    }

    public function logisticsCenters(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => LogisticsCenter::query()
                ->approvedActive()
                ->orderBy('business_name')
                ->get(['id', 'code', 'business_name'])
                ->values(),
        ]);
    }
}
