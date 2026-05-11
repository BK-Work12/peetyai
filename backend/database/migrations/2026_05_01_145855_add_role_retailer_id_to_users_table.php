<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'retailer_id')) {
                $table->foreignId('retailer_id')->nullable()->constrained()->nullOnDelete()->after('id');
            }
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 20)->nullable()->index()->after('email');
            }
            if (! Schema::hasColumn('users', 'role')) {
                $table->string('role', 20)->default('staff')->index()->after('phone');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['retailer_id', 'phone', 'role']);
        });
    }
};
