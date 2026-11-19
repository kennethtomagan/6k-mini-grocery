<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('cashier')->after('password')->index();
            $table->boolean('is_active')->default(true)->after('role');
            $table->boolean('can_override_price')->default(false)->after('is_active');
            $table->boolean('can_apply_discount')->default(false)->after('can_override_price');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active', 'can_override_price', 'can_apply_discount']);
        });
    }
};
