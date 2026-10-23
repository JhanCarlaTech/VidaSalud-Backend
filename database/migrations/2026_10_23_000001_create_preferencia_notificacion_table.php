<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CU05 – Preferencias de notificación de cada usuario (las consumirá CU20).
 * Tabla nueva: no existe en 'Base de Datos/script.sql'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preferencia_notificacion', function (Blueprint $table) {
            $table->bigInteger('id_usuario')->primary();
            $table->boolean('notificar_email')->default(true);
            $table->boolean('notificar_push')->default(true);
            $table->smallInteger('horas_anticipacion')->default(24);
            $table->timestampTz('fecha_actualizacion')->useCurrent();

            $table->foreign('id_usuario')->references('id')->on('usuario')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE preferencia_notificacion ADD CONSTRAINT chk_horas_anticipacion CHECK (horas_anticipacion IN (2, 12, 24, 48))');
    }

    public function down(): void
    {
        Schema::dropIfExists('preferencia_notificacion');
    }
};
