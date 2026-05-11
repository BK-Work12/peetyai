<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retailer_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('phone', 20);
            $table->string('preferred_language', 10)->default('en');
            $table->string('preferred_brand')->nullable();
            $table->unsignedInteger('lifetime_orders')->default(0);
            $table->decimal('lifetime_value', 12, 2)->default(0);
            $table->timestamp('last_order_at')->nullable();
            $table->json('preferences')->nullable();
            $table->timestamps();

            $table->unique(['retailer_id', 'phone']);
            $table->index(['retailer_id', 'last_order_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
