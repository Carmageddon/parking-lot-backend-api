<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ParkingSpace extends Model
{
    use HasFactory;

    protected $fillable = ['parking_lot_id', 'vehicle_type_id', 'parked_vehicle_type_id', 'space_number', 'is_occupied'];

    public function parkingLot()
    {
        return $this->belongsTo(ParkingLot::class);
    }

    public function vehicleType()
    {
        return $this->belongsTo(VehicleType::class);
    }
}
