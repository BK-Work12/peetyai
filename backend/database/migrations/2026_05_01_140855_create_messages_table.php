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
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retailer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 10)->index();
            $table->string('channel', 20)->default('whatsapp');
            $table->string('message_type', 20)->default('text');
            $table->string('external_id')->nullable()->index();
            $table->string('phone', 20)->index();
            $table->text('body')->nullable();
            $table->string('media_url')->nullable();
            $table->json('raw_payload')->nullable();
            $table->boolean('processed')->default(false)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
