<?php

namespace Tests\Unit;

use App\Models\ParkingLot;
use App\Models\ParkingSpace;
use App\Models\VehicleType;
use App\Services\ParkingLotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
use Illuminate\Support\Facades\Config;

class ParkingLotServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $parkingLotService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parkingLotService = new ParkingLotService();

        VehicleType::create(['name' => 'car']);
        VehicleType::create(['name' => 'motorcycle']);
        VehicleType::create(['name' => 'van']);
    }

    public function testSyncCapacityCache()
    {
        // Create a parking lot with some spaces
        $parkingLot = ParkingLot::factory()->create();
        $carType = VehicleType::where('name', 'car')->first();
        $motorcycleType = VehicleType::where('name', 'motorcycle')->first();
        $vanType = VehicleType::where('name', 'van')->first();
        ParkingSpace::factory()->count(5)->create([
            'parking_lot_id' => $parkingLot->id,
            'vehicle_type_id' => $carType->id,
            'is_occupied' => false,
        ]);

        // Sync the capacity cache
        $this->parkingLotService->syncCapacityCache($parkingLot->id);

        // Assert that Redis has been updated correctly
        $this->assertEquals(5, Redis::get("parking_lot:{$parkingLot->id}:total_capacity"), 'Total capacity should be 5');
        $this->assertEquals(5, Redis::get("parking_lot:{$parkingLot->id}:available_capacity"), 'Available capacity should be 5');
        $this->assertEquals(5, Redis::get("parking_lot:{$parkingLot->id}:capacity:{$carType->id}"), 'Capacity should be 5');
        $this->assertEquals(5, Redis::get("parking_lot:{$parkingLot->id}:available:{$carType->id}"), 'Available capacity should be 5');
    }

    public function testInitializeNewParkingLot()
    {
        $parkingLot = ParkingLot::factory()->create();
        $carType = VehicleType::where('name', 'car')->first();
        $motorcycleType = VehicleType::where('name', 'motorcycle')->first();
        $vanType = VehicleType::where('name', 'van')->first();

        $capacities = [
            'car' => 10,
            'motorcycle' => 5,
            'van' => 3,
        ];

        $this->parkingLotService->initializeNewParkingLot($parkingLot->id, $capacities);

        // Assert that parking spaces have been created
        $this->assertEquals(18, ParkingSpace::where('parking_lot_id', $parkingLot->id)->count());

        // Assert that Redis has been updated correctly
        $this->assertEquals(18, Redis::get("parking_lot:{$parkingLot->id}:total_capacity"), 'Total capacity should be 18');
        $this->assertEquals(18, Redis::get("parking_lot:{$parkingLot->id}:available_capacity"), 'Available capacity should be 18');
        $this->assertEquals(10, Redis::get("parking_lot:{$parkingLot->id}:capacity:{$carType->id}"), 'Capacity should be 10');
        $this->assertEquals(5, Redis::get("parking_lot:{$parkingLot->id}:capacity:{$motorcycleType->id}"), 'Capacity should be 5');
        $this->assertEquals(3, Redis::get("parking_lot:{$parkingLot->id}:capacity:{$vanType->id}"), 'Capacity should be 3');
    }

    public function testPark()
    {
        $parkingLot = ParkingLot::factory()->create();
        $carType = VehicleType::where('name', 'car')->first();
        ParkingSpace::factory()->create([
            'parking_lot_id' => $parkingLot->id,
            'vehicle_type_id' => $carType->id,
            'is_occupied' => false,
        ]);

        $this->parkingLotService->syncCapacityCache($parkingLot->id);

        $spaceNumber = $this->parkingLotService->park($parkingLot->id, 'car');

        $this->assertNotNull($spaceNumber);
        $this->assertEquals(0, Redis::get("parking_lot:{$parkingLot->id}:available:{$carType->id}"), 'Available capacity should be 0');
    }

    public function testUnpark()
    {
        $parkingLot = ParkingLot::factory()->create();
        $carType = VehicleType::where('name', 'car')->first();
        $space = ParkingSpace::factory()->create([
            'parking_lot_id' => $parkingLot->id,
            'vehicle_type_id' => $carType->id,
            'parked_vehicle_type_id' => $carType->id,
            'is_occupied' => true,
        ]);

        $this->parkingLotService->syncCapacityCache($parkingLot->id);

        $this->parkingLotService->unpark($parkingLot->id, $space->space_number);

        $this->assertFalse($space->fresh()->is_occupied);
        $this->assertEquals(1, Redis::get("parking_lot:{$parkingLot->id}:available:{$carType->id}"), 'Available capacity should be 1');
    }

    public function testParkVanNoSpaceAvailable()
    {
        $parkingLot = ParkingLot::factory()->create();
        $vanType = VehicleType::where('name', 'van')->first();
        $carType = VehicleType::where('name', 'car')->first();

        // Create 1 occupied van space and 2 occupied car spaces
        ParkingSpace::factory()->create([
            'parking_lot_id' => $parkingLot->id,
            'vehicle_type_id' => $vanType->id,
            'is_occupied' => true,
        ]);
        ParkingSpace::factory()->count(2)->create([
            'parking_lot_id' => $parkingLot->id,
            'vehicle_type_id' => $carType->id,
            'is_occupied' => true,
        ]);

        $this->parkingLotService->syncCapacityCache($parkingLot->id);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No available parking spaces for this vehicle type');

        $this->parkingLotService->park($parkingLot->id, 'van');
    }

    public function testParkVanInVanSpace()
    {
        $parkingLot = ParkingLot::factory()->create();
        $vanType = VehicleType::where('name', 'van')->first();

        // Create 1 free van space
        $vanSpace = ParkingSpace::factory()->create([
            'parking_lot_id' => $parkingLot->id,
            'vehicle_type_id' => $vanType->id,
            'is_occupied' => false,
        ]);

        $this->parkingLotService->syncCapacityCache($parkingLot->id);

        $spaceNumber = $this->parkingLotService->park($parkingLot->id, 'van');

        $this->assertEquals($vanSpace->space_number, $spaceNumber);
        $this->assertTrue($vanSpace->fresh()->is_occupied);
    }

    public function testParkVanInCarSpaces()
    {
        $parkingLot = ParkingLot::factory()->create();
        $vanType = VehicleType::where('name', 'van')->first();
        $carType = VehicleType::where('name', 'car')->first();

        // Create 3 consecutive free car spaces
        $startingSpaceNumber = 1;
        $carSpaces = collect();
        for ($i = 0; $i < 3; $i++) {
            $space = ParkingSpace::factory()->create([
                'parking_lot_id' => $parkingLot->id,
                'vehicle_type_id' => $carType->id,
                'is_occupied' => false,
                'space_number' => $startingSpaceNumber + $i,
            ]);
            $carSpaces->push($space);
        }

        // Ensure there are no van spaces
        ParkingSpace::where('parking_lot_id', $parkingLot->id)
            ->where('vehicle_type_id', $vanType->id)
            ->delete();

        $this->parkingLotService->syncCapacityCache($parkingLot->id);

        // Assert initial state
        $this->assertEquals(3, Redis::get("parking_lot:{$parkingLot->id}:available:{$carType->id}"));
        $this->assertEquals(0, Redis::get("parking_lot:{$parkingLot->id}:available:{$vanType->id}"));

        // Attempt to park a van
        $spaceNumber = $this->parkingLotService->park($parkingLot->id, 'van');

        // Assert the van was parked in the first car space
        $this->assertEquals($carSpaces->first()->space_number, $spaceNumber);

        // Assert all three car spaces are now occupied
        foreach ($carSpaces as $space) {
            $space->refresh();
            $this->assertTrue($space->is_occupied);
        }

        // Assert the cache has been updated correctly
        $this->assertEquals(0, Redis::get("parking_lot:{$parkingLot->id}:available:{$carType->id}"));
        $this->assertEquals(0, Redis::get("parking_lot:{$parkingLot->id}:available:{$vanType->id}"));
    }

    public function testParkVanPreferVanSpace()
    {
        $parkingLot = ParkingLot::factory()->create();
        $vanType = VehicleType::where('name', 'van')->first();
        $carType = VehicleType::where('name', 'car')->first();

        // Create 1 free van space and 3 free car spaces
        $vanSpace = ParkingSpace::factory()->create([
            'parking_lot_id' => $parkingLot->id,
            'vehicle_type_id' => $vanType->id,
            'is_occupied' => false,
        ]);
        ParkingSpace::factory()->count(3)->create([
            'parking_lot_id' => $parkingLot->id,
            'vehicle_type_id' => $carType->id,
            'is_occupied' => false,
        ]);

        $this->parkingLotService->syncCapacityCache($parkingLot->id);

        $spaceNumber = $this->parkingLotService->park($parkingLot->id, 'van');

        $this->assertEquals($vanSpace->space_number, $spaceNumber);
        $this->assertTrue($vanSpace->fresh()->is_occupied);

        // Check that car spaces are still unoccupied
        $this->assertEquals(3, ParkingSpace::where('parking_lot_id', $parkingLot->id)
            ->where('vehicle_type_id', $carType->id)
            ->where('is_occupied', false)
            ->count());
    }

    public function testVanParksInThreeConsecutiveCarSpaces()
    {
        $parkingLot = $this->createParkingLotWithConsecutiveCarSpaces(3);
        $this->removeAllVanSpaces($parkingLot);
        $this->syncCapacityCache($parkingLot);

        $this->assertInitialParkingState($parkingLot);

        $spaceNumber = $this->parkVan($parkingLot);

        $this->assertVanParkedInFirstCarSpace($parkingLot, $spaceNumber);
        $this->assertAllCarSpacesOccupied($parkingLot);
        $this->assertFinalParkingState($parkingLot);
    }

    private function createParkingLotWithConsecutiveCarSpaces(int $count): ParkingLot
    {
        $parkingLot = ParkingLot::factory()->create();
        $carType = VehicleType::where('name', 'car')->first();

        for ($i = 0; $i < $count; $i++) {
            ParkingSpace::factory()->create([
                'parking_lot_id' => $parkingLot->id,
                'vehicle_type_id' => $carType->id,
                'is_occupied' => false,
                'space_number' => $i + 1,
            ]);
        }

        return $parkingLot;
    }

    private function removeAllVanSpaces(ParkingLot $parkingLot): void
    {
        $vanType = VehicleType::where('name', 'van')->first();
        ParkingSpace::where('parking_lot_id', $parkingLot->id)
            ->where('vehicle_type_id', $vanType->id)
            ->delete();
    }

    private function syncCapacityCache(ParkingLot $parkingLot): void
    {
        $this->parkingLotService->syncCapacityCache($parkingLot->id);
    }

    private function assertInitialParkingState(ParkingLot $parkingLot): void
    {
        $carType = VehicleType::where('name', 'car')->first();
        $vanType = VehicleType::where('name', 'van')->first();

        $this->assertEquals(3, Redis::get("parking_lot:{$parkingLot->id}:available:{$carType->id}"));
        $this->assertEquals(0, Redis::get("parking_lot:{$parkingLot->id}:available:{$vanType->id}"));
    }

    private function parkVan(ParkingLot $parkingLot): int
    {
        return $this->parkingLotService->park($parkingLot->id, 'van');
    }

    private function assertVanParkedInFirstCarSpace(ParkingLot $parkingLot, int $spaceNumber): void
    {
        $carSpaces = ParkingSpace::where('parking_lot_id', $parkingLot->id)
            ->where('vehicle_type_id', VehicleType::where('name', 'car')->first()->id)
            ->get();

        $this->assertEquals($carSpaces->first()->space_number, $spaceNumber);
    }

    private function assertAllCarSpacesOccupied(ParkingLot $parkingLot): void
    {
        $carSpaces = ParkingSpace::where('parking_lot_id', $parkingLot->id)
            ->where('vehicle_type_id', VehicleType::where('name', 'car')->first()->id)
            ->get();

        foreach ($carSpaces as $space) {
            $space->refresh();
            $this->assertTrue($space->is_occupied);
        }
    }

    private function assertFinalParkingState(ParkingLot $parkingLot): void
    {
        $carType = VehicleType::where('name', 'car')->first();
        $vanType = VehicleType::where('name', 'van')->first();

        $this->assertEquals(0, Redis::get("parking_lot:{$parkingLot->id}:available:{$carType->id}"));
        $this->assertEquals(0, Redis::get("parking_lot:{$parkingLot->id}:available:{$vanType->id}"));
    }

    public function testUnparkVanFreesThreeCarSpaces()
    {
        $parkingLot = ParkingLot::factory()->create();
        $vanType = VehicleType::where('name', 'van')->first();
        $carType = VehicleType::where('name', 'car')->first();

        // Create 3 consecutive car spaces
        $carSpaces = collect();
        for ($i = 1; $i <= 3; $i++) {
            $space = ParkingSpace::factory()->create([
                'parking_lot_id' => $parkingLot->id,
                'vehicle_type_id' => $carType->id,
                'space_number' => $i,
                'is_occupied' => false,
            ]);
            $carSpaces->push($space);
        }

        $this->parkingLotService->syncCapacityCache($parkingLot->id);

        // Park a van
        $spaceNumber = $this->parkingLotService->park($parkingLot->id, 'van');

        // Assert van was parked
        $this->assertEquals(1, $spaceNumber);
        $this->assertEquals(0, Redis::get("parking_lot:{$parkingLot->id}:available:{$carType->id}"));

        // Check that all three spaces are occupied by a van
        foreach ($carSpaces as $space) {
            $space->refresh();
            $this->assertTrue($space->is_occupied);
            $this->assertEquals($vanType->id, $space->parked_vehicle_type_id);
        }

        // Unpark the van
        $this->parkingLotService->unpark($parkingLot->id, $spaceNumber);

        // Assert all three spaces are now free
        $this->assertEquals(3, Redis::get("parking_lot:{$parkingLot->id}:available:{$carType->id}"));
        foreach ($carSpaces as $space) {
            $space->refresh();
            $this->assertFalse($space->is_occupied, "Space {$space->space_number} should be unoccupied");
            $this->assertNull($space->parked_vehicle_type_id);
        }
    }

    protected function tearDown(): void
    {
        Artisan::call('cache:clear');

        $prefix = Config::get('database.redis.options.prefix', '');

        // Clear Redis keys related to parking lots
        $keys = Redis::keys("{$prefix}parking_lot:*");

        foreach ($keys as $key) {
            Redis::del($key);
        }

        parent::tearDown();
    }
}
