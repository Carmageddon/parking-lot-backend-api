<?php

namespace App\Http\Controllers;

use App\Services\ParkingLotService;
use App\Models\ParkingLot;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ParkingLotController extends Controller
{
    protected $parkingLotService;

    public function __construct(ParkingLotService $parkingLotService)
    {
        $this->parkingLotService = $parkingLotService;
    }


    public function park(Request $request, $parkingLotId): JsonResponse
    {
        try {
            $request->validate([
                'vehicle_type' => 'required|in:car,motorcycle,van',
            ]);

            $spaceNumber = $this->parkingLotService->park($parkingLotId, $request->vehicle_type);
            return response()->json(['message' => 'Vehicle parked successfully', 'space_number' => $spaceNumber], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function unpark(Request $request, $parkingLotId): JsonResponse
    {
        try {
            $request->validate([
                'space_number' => 'required|integer|min:1',
            ]);

            $spaceNumber = $request->input('space_number');

            $this->parkingLotService->unpark($parkingLotId, $spaceNumber);
            return response()->json(['message' => 'Vehicle unparked successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function status($parkingLotId): JsonResponse
    {
        try {
            $status = $this->parkingLotService->status($parkingLotId);
            return response()->json($status, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function syncCapacityCache($parkingLotId): JsonResponse
    {
        try {
            $this->parkingLotService->syncCapacityCache($parkingLotId);
            return response()->json(['message' => 'Capacity cache synchronized successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function initializeNewParkingLot(Request $request, $parkingLotId): JsonResponse
    {
        try {
            $request->validate([
                'capacities' => 'required|array',
                'capacities.car' => 'required|integer|min:0',
                'capacities.motorcycle' => 'required|integer|min:0',
                'capacities.van' => 'required|integer|min:0',
            ]);

            $capacities = $request->input('capacities');

            $parkingLot = $this->parkingLotService->initializeNewParkingLot($parkingLotId, $capacities);
            return response()->json([
                'message' => 'New parking lot initialized successfully',
                'parking_lot_id' => $parkingLot->id
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function create(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'capacities' => 'required|array',
                'capacities.car' => 'required|integer|min:0',
                'capacities.motorcycle' => 'required|integer|min:0',
                'capacities.van' => 'required|integer|min:0',
            ]);

            $name = $request->input('name');
            $capacities = $request->input('capacities');

            $parkingLot = $this->parkingLotService->createNewParkingLot($name, $capacities);
            return response()->json([
                'message' => 'New parking lot created successfully',
                'parking_lot_id' => $parkingLot->id
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function reinitialize(Request $request, $parkingLotId): JsonResponse
    {
        try {
            $request->validate([
                'capacities' => 'required|array',
                'capacities.car' => 'required|integer|min:0',
                'capacities.motorcycle' => 'required|integer|min:0',
                'capacities.van' => 'required|integer|min:0',
            ]);

            $capacities = $request->input('capacities');

            $parkingLot = $this->parkingLotService->reinitializeParkingLot($parkingLotId, $capacities);
            return response()->json([
                'message' => 'Parking lot reinitialized successfully',
                'parking_lot_id' => $parkingLot->id
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }
}
