<?php

use App\Modules\ModuloOperacionCitas\Models\MetodoPago;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CU24 – Pasarela de pago Stripe.
 *
 * 'metodo_pago.pasarela' indica qué pasarela cobra el método en línea (hoy solo
 * 'stripe'). Los métodos sin pasarela (QR, transferencia, efectivo) los verifica
 * el personal. El método de tarjeta existente pasa a cobrarse con Stripe.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE metodo_pago ADD COLUMN IF NOT EXISTS pasarela VARCHAR(30)');

        MetodoPago::asignarPasarelas();
    }

    public function down(): void
    {
        DB::table('metodo_pago')
            ->where('pasarela', MetodoPago::PASARELA_STRIPE)
            ->update(['descripcion' => 'Pago en línea con Visa, Mastercard o Red Enlace']);

        DB::statement('ALTER TABLE metodo_pago DROP COLUMN IF EXISTS pasarela');
    }
};
