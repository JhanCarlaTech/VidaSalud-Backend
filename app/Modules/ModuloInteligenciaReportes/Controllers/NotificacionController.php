<?php

namespace App\Modules\ModuloInteligenciaReportes\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloInteligenciaReportes\Models\Notificacion;
use App\Modules\ModuloInteligenciaReportes\Requests\AvisoRequest;
use App\Modules\ModuloInteligenciaReportes\Resources\NotificacionResource;
use App\Modules\ModuloInteligenciaReportes\Services\NotificacionService;
use App\Modules\ModuloPacientes\Models\Paciente;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CU20 – Gestión de notificaciones por Recepción y Administración.
 */
class NotificacionController extends Controller
{
    private const POR_PAGINA = 15;

    public function __construct(private readonly NotificacionService $notificaciones) {}

    /**
     * GET /api/notificaciones?buscar=&tipo=&estado_correo=&desde=&hasta=&page=
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'tipo' => ['nullable', Rule::in(Notificacion::TIPOS)],
            'estado_correo' => ['nullable', Rule::in(Notificacion::ESTADOS_CORREO)],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ], [
            'tipo.in' => 'El tipo de notificación no es válido.',
            'estado_correo.in' => 'El estado del correo no es válido.',
            'desde.date_format' => 'La fecha inicial no es válida.',
            'hasta.date_format' => 'La fecha final no es válida.',
            'hasta.after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.',
        ]);

        $pagina = $this->notificaciones->listar([
            'buscar' => $request->filled('buscar') ? trim($request->input('buscar')) : null,
            'tipo' => $request->input('tipo'),
            'estado_correo' => $request->input('estado_correo'),
            'desde' => $request->input('desde'),
            'hasta' => $request->input('hasta'),
        ], self::POR_PAGINA);

        return ApiResponse::exito([
            'items' => NotificacionResource::collection($pagina->items()),
            'meta' => [
                'pagina_actual' => $pagina->currentPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'por_pagina' => $pagina->perPage(),
                'total' => $pagina->total(),
            ],
            'tipos' => collect(Notificacion::TITULOS)->map(fn ($titulo, $tipo) => ['valor' => $tipo, 'etiqueta' => $titulo])->values(),
        ], 'Notificaciones.');
    }

    /**
     * POST /api/notificaciones: aviso manual a un paciente (RN05).
     */
    public function store(AvisoRequest $request): JsonResponse
    {
        $paciente = Paciente::query()->findOrFail($request->integer('id_paciente'));
        $notificacion = $this->notificaciones->enviarAviso(
            $paciente,
            $request->filled('id_cita') ? $request->integer('id_cita') : null,
            $request->validated('mensaje'),
            $request->user(),
        );

        $mensaje = match ($notificacion->estado_correo) {
            Notificacion::CORREO_ENVIADO => 'Aviso enviado al paciente por correo y en su bandeja.',
            Notificacion::CORREO_OMITIDO => 'Aviso enviado a la bandeja del paciente (no recibe correos).',
            default => 'Aviso guardado en la bandeja del paciente, pero el correo no se pudo enviar. Puede reenviarlo.',
        };

        return ApiResponse::exito(new NotificacionResource($notificacion->load(['paciente.usuario', 'cita'])), $mensaje, 201);
    }

    /**
     * POST /api/notificaciones/{notificacion}/reenviar (RN06)
     */
    public function reenviar(Request $request, Notificacion $notificacion): JsonResponse
    {
        $notificacion = $this->notificaciones->reenviar($notificacion, $request->user());

        return ApiResponse::exito(
            new NotificacionResource($notificacion->load(['paciente.usuario', 'cita'])),
            $notificacion->estado_correo === Notificacion::CORREO_ENVIADO ? 'Correo reenviado correctamente.' : 'El correo no se pudo enviar. Intente más tarde.',
        );
    }

    /**
     * POST /api/notificaciones/recordatorios: ejecuta ahora el envío de recordatorios (RN04).
     */
    public function recordatorios(Request $request): JsonResponse
    {
        $cantidad = $this->notificaciones->enviarRecordatorios();

        return ApiResponse::exito(['recordatorios' => $cantidad], match ($cantidad) {
            0 => 'No hay citas pendientes de recordatorio en este momento.',
            1 => 'Se envió 1 recordatorio.',
            default => "Se enviaron {$cantidad} recordatorios.",
        });
    }
}
