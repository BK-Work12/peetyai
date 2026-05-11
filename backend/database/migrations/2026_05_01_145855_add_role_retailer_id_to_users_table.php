<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'retailer_id')) {
                $table->foreignId('retailer_id')->nullable()->after('id');
            }
            if (! Schema::hasColumn('users', 'phone')) {
                $table->string('phone', 20)->nullable()->index()->after('email');
            }
            if (! Schema::hasColumn('users', 'role')) {
                $table->string('role', 20)->default('staff')->index()->after('phone');
            }
        });

        if (Schema::hasTable('retailers') && Schema::hasColumn('users', 'retailer_id')) {
            $databaseName = DB::getDatabaseName();
            $constraint = DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', $databaseName)
                ->where('TABLE_NAME', 'users')
                ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
                ->where('CONSTRAINT_NAME', 'users_retailer_id_foreign')
                ->exists();

            if (! $constraint) {
                Schema::table('users', function (Blueprint $table) {
                    $table->foreign('retailer_id')->references('id')->on('retailers')->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['retailer_id', 'phone', 'role']);
        });
    }
};
