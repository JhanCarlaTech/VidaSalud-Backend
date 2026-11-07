<?php

namespace App\Modules\ModuloOperacionCitas\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Método de pago de la pasarela (tabla 'metodo_pago', CU24).
 *
 * 'pasarela' es null para los métodos que verifica el personal (QR, transferencia,
 * efectivo) y 'stripe' para el pago con tarjeta en línea.
 */
#[Table(name: 'metodo_pago', timestamps: false)]
#[Fillable(['nombre', 'descripcion', 'activo', 'pasarela'])]
class MetodoPago extends Model
{
    public const PASARELA_STRIPE = 'stripe';

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /**
     * El método de tarjeta se cobra con Stripe. Lo usan la migración (bases
     * existentes) y DatabaseSeeder (bases nuevas, donde los métodos se insertan
     * después de migrar). Es idempotente.
     */
    public static function asignarPasarelas(): void
    {
        DB::table('metodo_pago')
            ->where('nombre', 'ilike', 'Tarjeta%')
            ->update([
                'pasarela' => self::PASARELA_STRIPE,
                'descripcion' => 'Pago en línea seguro con Visa o Mastercard, procesado por Stripe',
            ]);
    }

    /** ¿El cobro lo hace una pasarela en línea (y no se registra a mano)? */
    public function esEnLinea(): bool
    {
        return $this->pasarela !== null;
    }
}
