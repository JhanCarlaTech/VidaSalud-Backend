<?php

namespace App\Modules\ModuloInteligenciaReportes\Services;

use App\Modules\ModuloPersonalMedico\Models\Medico;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Modules\ModuloSeguridad\Services\AuthService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * CU21 RN04 – Alcance de los reportes, compartido por CU21 y CU22.
 *
 * Administración y Recepción ven toda la clínica. Un Médico (que en el script también
 * tiene 'reportes_ver') solo ve sus propias citas, atenciones e inasistencias, y no los
 * ingresos. Se considera médico con alcance personal a quien tiene ficha de médico y no
 * gestiona la agenda de la clínica ('citas_programar').
 */
class AlcanceReportes
{
    public function __construct(private readonly AuthService $auth) {}

    /** Médico con alcance personal, o null si el usuario ve toda la clínica. */
    public function medicoPersonal(Usuario $usuario): ?Medico
    {
        if ($this->auth->permisos($usuario)->contains('citas_programar')) {
            return null;
        }

        return Medico::query()->find($usuario->id);
    }

    /** Reportes que el usuario puede generar. */
    public function reportesPermitidos(Usuario $usuario): array
    {
        $personal = $this->medicoPersonal($usuario) !== null;

        return array_keys(array_filter(CatalogoReportes::REPORTES, fn (array $definicion) => ! $personal || $definicion['personal']));
    }

    /**
     * Filtros con el alcance aplicado: el médico con alcance personal siempre se filtra a sí mismo.
     *
     * @throws AccessDeniedHttpException si el reporte no está en su alcance.
     */
    public function aplicar(Usuario $usuario, string $tipo, array $filtros): array
    {
        $personal = $this->medicoPersonal($usuario);

        if ($personal) {
            if (! CatalogoReportes::definicion($tipo)['personal']) {
                throw new AccessDeniedHttpException;
            }
            $filtros['medico'] = $personal->id_usuario;
        }

        return $filtros;
    }
}
