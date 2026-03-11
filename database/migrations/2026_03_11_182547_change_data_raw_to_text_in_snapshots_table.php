<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Change data_raw to text for base64 storage (PostgreSQL rejects binary in UTF-8).
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE snapshots ALTER COLUMN data_raw TYPE TEXT USING CASE WHEN data_raw IS NULL THEN NULL ELSE encode(data_raw, 'base64') END");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE snapshots ALTER COLUMN data_raw TYPE BYTEA USING CASE WHEN data_raw IS NULL THEN NULL ELSE decode(data_raw, 'base64') END");
        }
    }
};
