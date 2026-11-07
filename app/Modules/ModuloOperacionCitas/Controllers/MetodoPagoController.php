<?php

namespace App\Modules\ModuloOperacionCitas\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloOperacionCitas\Models\MetodoPago;
use App\Modules\ModuloOperacionCitas\Services\PasarelaStripe;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Catálogo de métodos de pago activos (CU24).
 */
class MetodoPagoController extends Controller
{
    public function __construct(
        private readonly PasarelaStripe $stripe
    ) {}

    /**
     * GET /api/metodos-pago
     * 'en_linea' marca el método que se cobra con Stripe; si Stripe no está
     * configurado, ese método no se ofrece.
     */
    public function index(): JsonResponse
    {
        $metodos = MetodoPago::query()
            ->where('activo', true)
            ->when(! $this->stripe->configurada(), fn ($consulta) => $consulta->whereNull('pasarela'))
            ->orderBy('id')
            ->get(['id', 'nombre', 'descripcion', 'pasarela'])
            ->map(fn (MetodoPago $metodo) => [
                'id' => $metodo->id,
                'nombre' => $metodo->nombre,
                'descripcion' => $metodo->descripcion,
                'en_linea' => $metodo->esEnLinea(),
            ]);

        return ApiResponse::exito($metodos, 'Métodos de pago.');
    }
}
