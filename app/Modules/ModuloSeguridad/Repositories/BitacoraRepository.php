<?php

namespace App\Modules\ModuloSeguridad\Repositories;

use App\Modules\ModuloSeguridad\Models\Bitacora;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Consultas de la bitácora de auditoría (CU06). Solo lectura: la bitácora
 * se escribe únicamente desde BitacoraService::registrar().
 */
class BitacoraRepository
{
    /** Columnas del autor que se cargan con cada evento. */
    private const COLUMNAS_USUARIO = 'usuario:id,ci,nombre,apellido,correo';

    public function paginar(array $filtros, int $porPagina): LengthAwarePaginator
    {
        return $this->consulta($filtros)
            ->with(self::COLUMNAS_USUARIO)
            ->orderByDesc('id')
            ->paginate($porPagina);
    }

    public function contar(array $filtros): int
    {
        return $this->consulta($filtros)->count();
    }

    /**
     * Eventos filtrados, del más reciente al más antiguo, leídos por bloques
     * para exportar sin cargar todo en memoria.
     *
     * @return LazyCollection<int, Bitacora>
     */
    public function recorrer(array $filtros): LazyCollection
    {
        return $this->consulta($filtros)->with(self::COLUMNAS_USUARIO)->lazyByIdDesc(500);
    }

    /**
     * Valores registrados de una columna ('accion' o 'tabla_afectada'), para los filtros.
     *
     * @return Collection<int, string>
     */
    public function valoresDistintos(string $columna): Collection
    {
        return Bitacora::query()->distinct()->orderBy($columna)->pluck($columna);
    }

    /**
     * @param  array{buscar: ?string, accion: ?string, tabla: ?string, ip: ?string, desde: ?\DateTimeInterface, hasta: ?\DateTimeInterface}  $filtros
     */
    private function consulta(array $filtros): Builder
    {
        return Bitacora::query()
            ->when($filtros['buscar'], function (Builder $query, string $termino) {
                $patron = '%'.addcslashes($termino, '\\%_').'%';

                // Autor del evento por CI, nombre o correo; o el correo de un intento de
                // inicio de sesión fallido, que puede no corresponder a ningún usuario.
                $query->where(function (Builder $query) use ($patron) {
                    $query->whereHas('usuario', function (Builder $usuario) use ($patron) {
                        $usuario->where(function (Builder $usuario) use ($patron) {
                            $usuario->where('ci', 'ilike', $patron)
                                ->orWhere('correo', 'ilike', $patron)
                                ->orWhereRaw("(nombre || ' ' || apellido) ilike ?", [$patron]);
                        });
                    })->orWhereRaw("datos_nuevos->>'correo' ilike ?", [$patron]);
                });
            })
            ->when($filtros['accion'], fn (Builder $query, string $accion) => $query->where('accion', $accion))
            ->when($filtros['tabla'], fn (Builder $query, string $tabla) => $query->where('tabla_afectada', $tabla))
            // La IP se busca por su inicio: '192.168.' encuentra toda la red.
            ->when($filtros['ip'], fn (Builder $query, string $ip) => $query->where('ip_origen', 'like', addcslashes($ip, '\\%_').'%'))
            // Con zona horaria explícita, para que PostgreSQL no use la de la conexión.
            ->when($filtros['desde'], fn (Builder $query, $desde) => $query->where('fecha_registro', '>=', $desde->toIso8601String()))
            ->when($filtros['hasta'], fn (Builder $query, $hasta) => $query->where('fecha_registro', '<', $hasta->toIso8601String()));
    }
}
