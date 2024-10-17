<?php

namespace Database\Factories;

use App\Models\ParkingLot;
use Illuminate\Database\Eloquent\Factories\Factory;

class ParkingLotFactory extends Factory
{
    protected $model = ParkingLot::class;

    public function definition()
    {
        return [
            'name' => $this->faker->unique()->word . ' Parking Lot',
        ];
    }
}
