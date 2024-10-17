<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class CreateVehicleTypesTable extends Migration
{
    public function up()
    {
        Schema::create('vehicle_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        // Insert default vehicle types
        DB::table('vehicle_types')->insert([
            ['name' => 'car'],
            ['name' => 'motorcycle'],
            ['name' => 'van'],
        ]);
    }

    public function down()
    {
        Schema::dropIfExists('vehicle_types');
    }
}
