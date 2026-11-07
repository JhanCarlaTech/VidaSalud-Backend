<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CU20 – Notificaciones push (Web Push).
 *
 * Una fila por navegador o dispositivo en el que el usuario activó los avisos.
 * El endpoint es la dirección del servicio push del navegador (Google, Mozilla,
 * Apple…); las claves cifran el contenido para que solo ese navegador lo lea.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suscripcion_push', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('id_usuario');
            $table->text('endpoint')->unique();
            $table->string('clave_p256dh', 255);
            $table->string('clave_auth', 255);
            $table->string('codificacion', 20)->default('aes128gcm');
            $table->string('agente', 255)->nullable(); // navegador, para que el usuario reconozca el dispositivo
            $table->timestampTz('fecha_creacion')->useCurrent();
            $table->timestampTz('fecha_ultimo_envio')->nullable();

            $table->foreign('id_usuario')->references('id')->on('usuario')->cascadeOnDelete();
            $table->index('id_usuario');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suscripcion_push');
    }
};
