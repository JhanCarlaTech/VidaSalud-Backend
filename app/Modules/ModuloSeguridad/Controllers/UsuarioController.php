<?php

namespace App\Modules\ModuloSeguridad\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Modules\ModuloSeguridad\Requests\UsuarioCreateRequest;
use App\Modules\ModuloSeguridad\Requests\UsuarioUpdateRequest;
use App\Modules\ModuloSeguridad\Resources\UsuarioResource;
use App\Modules\ModuloSeguridad\Services\UsuarioService;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\Requests\CambiarEstadoRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CU02 – Gestionar Usuarios y Asignar Roles.
 */
class UsuarioController extends Controller
{
    private const POR_PAGINA = 10;

    public function __construct(private readonly UsuarioService $usuarios) {}

    /**
     * GET /api/usuarios?buscar=&rol=&activo=&page=&por_pagina=
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'buscar' => ['nullable', 'string', 'max:100'],
            'rol' => ['nullable', 'integer'],
            'activo' => ['nullable', 'boolean'],
            'por_pagina' => ['nullable', 'integer', 'min:5', 'max:50'],
        ]);

        $filtros = [
            'buscar' => $request->filled('buscar') ? trim($request->input('buscar')) : null,
            'rol' => $request->filled('rol') ? $request->integer('rol') : null,
            'activo' => $request->filled('activo') ? $request->boolean('activo') : null,
        ];

        $pagina = $this->usuarios->listar($filtros, $request->integer('por_pagina', self::POR_PAGINA));

        return ApiResponse::exito([
            'items' => UsuarioResource::collection($pagina->items()),
            'meta' => [
                'pagina_actual' => $pagina->currentPage(),
                'ultima_pagina' => $pagina->lastPage(),
                'por_pagina' => $pagina->perPage(),
                'total' => $pagina->total(),
            ],
        ], 'Listado de usuarios.');
    }

    /**
     * GET /api/usuarios/{usuario}
     */
    public function show(Usuario $usuario): JsonResponse
    {
        return ApiResponse::exito(new UsuarioResource($this->usuarios->detalle($usuario)), 'Detalle del usuario.');
    }

    /**
     * POST /api/usuarios
     */
    public function store(UsuarioCreateRequest $request): JsonResponse
    {
        $usuario = $this->usuarios->crear($request->validated(), $request->user());

        return ApiResponse::exito(new UsuarioResource($usuario), 'Usuario registrado correctamente.', 201);
    }

    /**
     * PUT /api/usuarios/{usuario}
     */
    public function update(UsuarioUpdateRequest $request, Usuario $usuario): JsonResponse
    {
        $usuario = $this->usuarios->actualizar($usuario, $request->validated(), $request->user());

        return ApiResponse::exito(new UsuarioResource($usuario), 'Usuario actualizado correctamente.');
    }

    /**
     * PATCH /api/usuarios/{usuario}/estado
     */
    public function cambiarEstado(CambiarEstadoRequest $request, Usuario $usuario): JsonResponse
    {
        $activo = $request->boolean('activo');
        $usuario = $this->usuarios->cambiarEstado($usuario, $activo, $request->user());

        return ApiResponse::exito(
            new UsuarioResource($usuario),
            $activo ? 'Usuario activado correctamente.' : 'Usuario desactivado correctamente.',
        );
    }
}
