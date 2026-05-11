<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unique(['id', 'retailer_id'], 'customers_id_retailer_unique');
        });

        Schema::create('customer_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retailer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('typical_order_frequency', 40)->nullable();
            $table->json('top_brands')->nullable();
            $table->decimal('avg_basket_value', 12, 2)->default(0);
            $table->string('preferred_delivery_window', 40)->nullable();
            $table->timestamp('last_order_at')->nullable();
            $table->unsignedInteger('total_orders')->default(0);
            $table->timestamps();

            $table->unique(['retailer_id', 'customer_id']);
            $table->foreign(['customer_id', 'retailer_id'])
                ->references(['id', 'retailer_id'])
                ->on('customers')
                ->cascadeOnDelete();
        });

        Schema::create('customer_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retailer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->text('fact');
            $table->string('fact_hash', 64);
            $table->string('source_conversation_id')->nullable();
            $table->timestamp('last_referenced_at')->nullable();
            $table->decimal('confidence', 4, 3)->default(0.7);
            $table->unsignedInteger('reference_count')->default(0);
            $table->boolean('pii_redacted')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['retailer_id', 'customer_id', 'fact_hash'], 'customer_memories_fact_unique');
            $table->index(['retailer_id', 'customer_id', 'deleted_at']);
            $table->foreign(['customer_id', 'retailer_id'])
                ->references(['id', 'retailer_id'])
                ->on('customers')
                ->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE customer_memories ENABLE ROW LEVEL SECURITY');
            DB::statement("CREATE POLICY customer_memories_tenant_policy ON customer_memories
                USING (
                    retailer_id = current_setting('app.current_retailer_id', true)::bigint
                    AND EXISTS (
                        SELECT 1 FROM customers c
                        WHERE c.id = customer_memories.customer_id
                          AND c.retailer_id = customer_memories.retailer_id
                          AND c.phone = current_setting('app.current_customer_phone', true)
                    )
                )");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY IF EXISTS customer_memories_tenant_policy ON customer_memories');
        }

        Schema::dropIfExists('customer_memories');
        Schema::dropIfExists('customer_insights');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique('customers_id_retailer_unique');
        });
    }
};
