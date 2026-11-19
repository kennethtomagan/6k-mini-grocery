<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            // Set immediately after insert, inside the same DB transaction (needs the id).
            $table->string('reference_number', 32)->nullable()->unique();
            $table->uuid('checkout_token')->unique(); // idempotency key: one sale per POS cart
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('completed');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->decimal('cash_received', 12, 2);
            $table->decimal('change_amount', 12, 2);
            $table->unsignedInteger('item_count');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
