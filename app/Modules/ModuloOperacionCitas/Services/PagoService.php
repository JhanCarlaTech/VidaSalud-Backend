<?php

namespace App\Modules\ModuloOperacionCitas\Services;

use App\Modules\ModuloOperacionCitas\Models\PagoCita;
use App\Modules\ModuloSeguridad\Services\BitacoraService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PagoService
{
    public function __construct(
        private readonly BitacoraService $bitacora
    ) {}

    public function registrar(array $datos, int $idUsuario): PagoCita
    {
        return DB::transaction(function () use ($datos, $idUsuario) {
            
            // Verificar que la cita no tenga ya un pago registrado (índice UNIQUE en la BD)
            if (PagoCita::where('id_cita', $datos['id_cita'])->exists()) {
                throw ValidationException::withMessages([
                    'id_cita' => 'Esta cita ya tiene un pago registrado en el sistema.'
                ]);
            }

            // Crear el registro del pago
            $pago = PagoCita::create([
                'id_cita' => $datos['id_cita'],
                'id_metodo_pago' => $datos['id_metodo_pago'],
                'monto' => $datos['monto'],
                'moneda' => 'BOB', // Por defecto según tu BD
                'codigo_transaccion' => $datos['codigo_transaccion'] ?? 'TXN-' . uniqid(),
                'estado' => $datos['estado'] ?? 'Completado',
                'detalles_pasarela' => $datos['detalles_pasarela'] ?? null,
            ]);

            // Registrar en la bitácora (Auditoría)
            $this->bitacora->registrar('INSERT', 'pago_cita', $idUsuario, datosNuevos: [
                'id_pago' => $pago->id,
                'id_cita' => $pago->id_cita,
                'monto' => $pago->monto,
                'estado' => $pago->estado
            ]);

            return $pago;
        });
    }
}