<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateParkingSpacesTable extends Migration
{
    public function up()
    {
        Schema::create('parking_spaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parking_lot_id')->constrained()->onDelete('cascade');
            $table->foreignId('vehicle_type_id')->constrained();
            $table->integer('space_number');
            $table->boolean('is_occupied')->default(false);
            $table->timestamps();

            $table->unique(['parking_lot_id', 'space_number']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('parking_spaces');
    }
}
