<?php

namespace App\Services;

use App\Models\ParkingLot;
use App\Models\ParkingSpace;
use App\Models\VehicleType;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ParkingLotService
{
    public function syncCapacityCache($parkingLotId)
    {
        $parkingLot = ParkingLot::findOrFail($parkingLotId);
        $totalCapacity = $parkingLot->parkingSpaces()->count();
        $availableCapacity = $parkingLot->parkingSpaces()->where('is_occupied', false)->count();

        Redis::set("parking_lot:{$parkingLotId}:total_capacity", $totalCapacity);
        Redis::set("parking_lot:{$parkingLotId}:available_capacity", $availableCapacity);

        $vehicleTypes = VehicleType::all();
        foreach ($vehicleTypes as $vehicleType) {
            $typeCapacity = $parkingLot->parkingSpaces()->where('vehicle_type_id', $vehicleType->id)->count();
            $typeAvailable = $parkingLot->parkingSpaces()
                ->where('vehicle_type_id', $vehicleType->id)
                ->where('is_occupied', false)
                ->count();

            Redis::set("parking_lot:{$parkingLotId}:capacity:{$vehicleType->id}", $typeCapacity);
            Redis::set("parking_lot:{$parkingLotId}:available:{$vehicleType->id}", $typeAvailable);
        }
    }

    public function initializeNewParkingLot($parkingLotId, $capacities)
    {
        return DB::transaction(function () use ($parkingLotId, $capacities) {
            $parkingLot = ParkingLot::firstOrCreate(['id' => $parkingLotId]);

            // Clear existing parking spaces if any
            $parkingLot->parkingSpaces()->delete();

            $spaceNumber = 1;
            foreach ($capacities as $vehicleTypeName => $capacity) {
                $vehicleType = VehicleType::where('name', $vehicleTypeName)->firstOrFail();
                for ($i = 0; $i < $capacity; $i++) {
                    ParkingSpace::create([
                        'parking_lot_id' => $parkingLot->id,
                        'vehicle_type_id' => $vehicleType->id,
                        'space_number' => $spaceNumber++,
                        'is_occupied' => false,
                    ]);
                }
            }

            $this->syncCapacityCache($parkingLot->id);

            return $parkingLot;
        });
    }

    public function park($parkingLotId, $vehicleType)
    {
        return DB::transaction(function () use ($parkingLotId, $vehicleType) {
            $parkingLot = ParkingLot::findOrFail($parkingLotId);
            $vehicleTypeId = $this->getVehicleTypeId($vehicleType);

            if ($this->hasAvailableSpaceForVehicleType($parkingLotId, $vehicleTypeId)) {
                return $this->parkInAvailableSpace($parkingLotId, $vehicleTypeId);
            }

            if ($vehicleType === 'van') {
                $spaceNumber = $this->attemptToParkVanInCarSpaces($parkingLotId);
                if ($spaceNumber !== null) {
                    return $spaceNumber;
                }
            }

            throw new \Exception('No available parking spaces for this vehicle type');
        });
    }

    public function unpark($parkingLotId, $spaceNumber)
    {
        return DB::transaction(function () use ($parkingLotId, $spaceNumber) {
            try {
                $space = ParkingSpace::where('parking_lot_id', $parkingLotId)
                    ->where('space_number', $spaceNumber)
                    ->where('is_occupied', true)
                    ->firstOrFail();

                $parkedVehicleType = VehicleType::findOrFail($space->parked_vehicle_type_id);

                if ($parkedVehicleType->name === 'van' && $space->vehicle_type_id === $this->getVehicleTypeId('car')) {
                    // Unpark van from car spaces
                    $spacesToFree = ParkingSpace::where('parking_lot_id', $parkingLotId)
                        ->where('space_number', '>=', $spaceNumber)
                        ->where('space_number', '<', $spaceNumber + 3)
                        ->where('is_occupied', true)
                        ->where('parked_vehicle_type_id', $space->parked_vehicle_type_id)
                        ->get();

                    foreach ($spacesToFree as $spaceToFree) {
                        $this->freeSpace($spaceToFree);
                    }

                    $this->incrementAvailableCapacity($parkingLotId, $space->vehicle_type_id, $spacesToFree->count());
                } else {
                    // Unpark regular vehicle
                    $this->freeSpace($space);
                    $this->incrementAvailableCapacity($parkingLotId, $space->vehicle_type_id);
                }

                return true;
            } catch (\Exception $e) {
                throw $e;
            }
        });
    }

    public function status($parkingLotId)
    {
        $parkingLot = ParkingLot::findOrFail($parkingLotId);
        $spaces = $parkingLot->parkingSpaces()->with('vehicleType')->get();

        $status = [
            'total_capacity' => Redis::get("parking_lot:{$parkingLotId}:total_capacity"),
            'available_capacity' => Redis::get("parking_lot:{$parkingLotId}:available_capacity"),
            'spaces' => []
        ];

        $vehicleTypes = VehicleType::all();
        foreach ($vehicleTypes as $vehicleType) {
            $status['capacity_by_type'][$vehicleType->name] = [
                'total' => Redis::get("parking_lot:{$parkingLotId}:capacity:{$vehicleType->id}"),
                'available' => Redis::get("parking_lot:{$parkingLotId}:available:{$vehicleType->id}")
            ];
        }

        foreach ($spaces as $space) {
            $status['spaces'][] = [
                'space_number' => $space->space_number,
                'vehicle_type' => $space->vehicleType->name,
                'is_occupied' => $space->is_occupied,
            ];
        }

        return $status;
    }

    private function getVehicleTypeId($vehicleType)
    {
        return VehicleType::where('name', $vehicleType)->firstOrFail()->id;
    }

    private function hasAvailableSpaceForVehicleType($parkingLotId, $vehicleTypeId)
    {
        return Redis::get("parking_lot:{$parkingLotId}:available:{$vehicleTypeId}") > 0;
    }

    private function parkInAvailableSpace($parkingLotId, $vehicleTypeId)
    {
        $space = $this->getAvailableSpace($parkingLotId, $vehicleTypeId);
        $this->occupySpace($space, $vehicleTypeId);
        $this->decrementAvailableCapacity($parkingLotId, $vehicleTypeId);
        return $space->space_number;
    }

    private function attemptToParkVanInCarSpaces($parkingLotId)
    {
        $carTypeId = $this->getVehicleTypeId('car');
        $vanTypeId = $this->getVehicleTypeId('van');
        if ($this->hasEnoughConsecutiveCarSpaces($parkingLotId)) {
            $carSpaces = $this->getConsecutiveCarSpaces($parkingLotId, 3);
            if ($carSpaces) {
                return $this->occupyConsecutiveSpaces($parkingLotId, $carSpaces, $vanTypeId);
            }
        }
        return null;
    }

    private function hasEnoughConsecutiveCarSpaces($parkingLotId)
    {
        $carTypeId = $this->getVehicleTypeId('car');
        return Redis::get("parking_lot:{$parkingLotId}:available:{$carTypeId}") >= 3;
    }

    private function occupyConsecutiveSpaces($parkingLotId, $spaces, $vehicleTypeId)
    {
        foreach ($spaces as $space) {
            $this->occupySpace($space, $vehicleTypeId);
        }
        $this->decrementAvailableCapacity($parkingLotId, $this->getVehicleTypeId('car'), 3);
        return $spaces->first()->space_number;
    }

    private function getConsecutiveCarSpaces($parkingLotId, $count)
    {
        $carTypeId = $this->getVehicleTypeId('car');
        $spaces = $this->getAvailableCarSpaces($parkingLotId);

        return $this->findConsecutiveSpaces($spaces, $count);
    }

    private function getAvailableCarSpaces($parkingLotId)
    {
        $carTypeId = $this->getVehicleTypeId('car');
        return ParkingSpace::where('parking_lot_id', $parkingLotId)
            ->where('vehicle_type_id', $carTypeId)
            ->where('is_occupied', false)
            ->orderBy('space_number')
            ->get();
    }

    private function findConsecutiveSpaces($spaces, $count)
    {
        $consecutive = collect();
        foreach ($spaces as $space) {
            if ($this->isNextConsecutiveSpace($consecutive, $space)) {
                $consecutive->push($space);
                if ($consecutive->count() == $count) {
                    return $consecutive;
                }
            } else {
                $consecutive = collect([$space]);
            }
        }
        return null;
    }

    private function isNextConsecutiveSpace($consecutive, $space)
    {
        return $consecutive->isEmpty() || $space->space_number == $consecutive->last()->space_number + 1;
    }

    private function getAvailableSpace($parkingLotId, $vehicleTypeId)
    {
        return ParkingSpace::where('parking_lot_id', $parkingLotId)
            ->where('vehicle_type_id', $vehicleTypeId)
            ->where('is_occupied', false)
            ->firstOrFail();
    }

    private function occupySpace($space, $vehicleTypeId)
    {
        $space->update([
            'is_occupied' => true,
            'parked_vehicle_type_id' => $vehicleTypeId
        ]);
    }

    private function decrementAvailableCapacity($parkingLotId, $vehicleTypeId, $count = 1)
    {
        Redis::decr("parking_lot:{$parkingLotId}:available_capacity", $count);
        Redis::decr("parking_lot:{$parkingLotId}:available:{$vehicleTypeId}", $count);
    }

    private function incrementAvailableCapacity($parkingLotId, $vehicleTypeId, $count = 1)
    {
        Redis::incr("parking_lot:{$parkingLotId}:available_capacity", $count);
        Redis::incr("parking_lot:{$parkingLotId}:available:{$vehicleTypeId}", $count);
    }

    public function createNewParkingLot($name, $capacities)
    {
        return DB::transaction(function () use ($name, $capacities) {
            $parkingLot = ParkingLot::create(['name' => $name]);
            $this->initializeParkingSpaces($parkingLot, $capacities);
            return $parkingLot;
        });
    }

    public function reinitializeParkingLot($parkingLotId, $capacities)
    {
        return DB::transaction(function () use ($parkingLotId, $capacities) {
            $parkingLot = ParkingLot::findOrFail($parkingLotId);
            $parkingLot->parkingSpaces()->delete();
            $this->initializeParkingSpaces($parkingLot, $capacities);
            return $parkingLot;
        });
    }

    private function initializeParkingSpaces(ParkingLot $parkingLot, $capacities)
    {
        $spaceNumber = 1;
        foreach ($capacities as $vehicleTypeName => $capacity) {
            $vehicleType = VehicleType::where('name', $vehicleTypeName)->firstOrFail();
            for ($i = 0; $i < $capacity; $i++) {
                ParkingSpace::create([
                    'parking_lot_id' => $parkingLot->id,
                    'vehicle_type_id' => $vehicleType->id,
                    'space_number' => $spaceNumber++,
                    'is_occupied' => false,
                ]);
            }
        }
        $this->syncCapacityCache($parkingLot->id);
    }

    private function freeSpace($space)
    {
        $space->update([
            'is_occupied' => false,
            'parked_vehicle_type_id' => null
        ]);
    }
}
