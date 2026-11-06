<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CU12 – Catálogo de enfermedades sin borrado físico (decisión D2).
 *
 * Una enfermedad que ya figura en antecedentes o diagnósticos no se elimina:
 * se desactiva. Las existentes quedan activas.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE enfermedad ADD COLUMN IF NOT EXISTS activo BOOLEAN NOT NULL DEFAULT TRUE');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE enfermedad DROP COLUMN IF EXISTS activo');
    }
};
