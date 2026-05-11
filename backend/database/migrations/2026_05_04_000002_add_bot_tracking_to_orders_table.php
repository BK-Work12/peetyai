<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('received_by_bot')->default(false)->index()->after('source');
            $table->timestamp('received_by_bot_at')->nullable()->index()->after('received_by_bot');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['received_by_bot', 'received_by_bot_at']);
        });
    }
};
