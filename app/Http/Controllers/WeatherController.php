<?php

namespace App\Http\Controllers;

use App\Services\Weather\OpenWeatherMapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WeatherController extends Controller
{
    public function current(Request $request, OpenWeatherMapService $weather): JsonResponse
    {
        $coordinates = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $conditions = $weather->currentAt(
            (float) $coordinates['latitude'],
            (float) $coordinates['longitude'],
        );

        if ($conditions === null) {
            return response()->json([
                'message' => 'Local weather is temporarily unavailable.',
            ], 503);
        }

        return response()->json($conditions)->header('Cache-Control', 'no-store');
    }
}
