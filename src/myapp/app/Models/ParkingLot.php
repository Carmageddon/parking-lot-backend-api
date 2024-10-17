<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ParkingLot extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

    public function parkingSpaces()
    {
        return $this->hasMany(ParkingSpace::class);
    }

    public function getCapacity()
    {
        return $this->parkingSpaces()->count();
    }

    public function getCapacityByType($vehicleTypeId)
    {
        return $this->parkingSpaces()->where('vehicle_type_id', $vehicleTypeId)->count();
    }
}
