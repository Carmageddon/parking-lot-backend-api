<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddParkedVehicleTypeIdToParkingSpaces extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('parking_spaces', function (Blueprint $table) {
            $table->unsignedBigInteger('parked_vehicle_type_id')->nullable()->after('vehicle_type_id');
            $table->foreign('parked_vehicle_type_id')->references('id')->on('vehicle_types');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('parking_spaces', function (Blueprint $table) {
            $table->dropForeign(['parked_vehicle_type_id']);
            $table->dropColumn('parked_vehicle_type_id');
        });
    }
}
