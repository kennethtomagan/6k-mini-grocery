<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name', 150); // snapshot
            $table->string('product_sku', 64)->nullable(); // snapshot
            $table->string('product_barcode', 64)->nullable(); // snapshot
            $table->boolean('is_manual')->default(false);
            $table->unsignedInteger('quantity');
            $table->decimal('original_price', 10, 2); // catalog price at sale time
            $table->decimal('unit_price', 10, 2); // price actually charged
            $table->decimal('unit_cost', 10, 2)->nullable(); // snapshot of cost_price
            $table->decimal('line_total', 12, 2);
            $table->boolean('price_overridden')->default(false);
            $table->foreignId('price_override_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_items');
    }
};
