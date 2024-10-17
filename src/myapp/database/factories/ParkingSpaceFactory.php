<?php

namespace Database\Factories;

use App\Models\ParkingSpace;
use App\Models\ParkingLot;
use App\Models\VehicleType;
use Illuminate\Database\Eloquent\Factories\Factory;

class ParkingSpaceFactory extends Factory
{
    protected $model = ParkingSpace::class;

    public function definition()
    {
        return [
            'parking_lot_id' => ParkingLot::factory(),
            'vehicle_type_id' => VehicleType::inRandomOrder()->first()->id,
            'space_number' => $this->faker->unique()->numberBetween(1, 1000),
            'is_occupied' => $this->faker->boolean,
        ];
    }
}
