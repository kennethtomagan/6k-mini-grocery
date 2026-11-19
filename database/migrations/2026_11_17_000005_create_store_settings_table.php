<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_settings', function (Blueprint $table) {
            $table->id();
            $table->string('store_name', 100)->default('My Sari-Sari Store');
            $table->string('store_address')->nullable();
            $table->string('store_phone', 50)->nullable();
            $table->string('receipt_footer')->nullable();
            $table->unsignedTinyInteger('receipt_paper_width')->default(58); // 58 or 80 (mm)
            $table->boolean('allow_negative_stock')->default(false);
            $table->timestamps();
        });

        DB::table('store_settings')->insert([
            'store_name' => 'My Sari-Sari Store',
            'receipt_footer' => 'Salamat po! Balik kayo.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('store_settings');
    }
};
