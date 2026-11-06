<?php

namespace App\Modules\ModuloInteligenciaReportes\Requests;

use App\Modules\ModuloInteligenciaReportes\Services\CatalogoReportes;
use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Filtros de un reporte de CU21 (E1). Solo se toman los filtros que admite el reporte
 * pedido (CatalogoReportes); los demás se ignoran.
 */
class ReporteRequest extends FormRequest
{
    /** Valores de 'estado_pago_enum'; 'todos' quita el filtro. */
    public const ESTADOS_PAGO = ['Pendiente', 'Completado', 'Rechazado', 'Reembolsado'];

    public const TODOS = 'todos';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $definicion = CatalogoReportes::definicion($this->tipo());

        return [
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'medico' => ['nullable', 'integer'],
            'especialidad' => ['nullable', 'integer'],
            'modalidad' => ['nullable', 'integer'],
            'metodo' => ['nullable', 'integer'],
            'estado' => ['nullable', Rule::in(CitaMedica::ESTADOS)],
            'estado_pago' => ['nullable', Rule::in([...self::ESTADOS_PAGO, self::TODOS])],
            'agrupar' => ['nullable', Rule::in($definicion['agrupaciones'])],
            'seccion' => ['nullable', Rule::in(['agrupado', 'detalle'])],
        ];
    }

    /** RN03: el periodo no supera un año. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['desde', 'hasta'])) {
                    return;
                }

                $dias = CarbonImmutable::parse($this->input('desde'))->diffInDays(CarbonImmutable::parse($this->input('hasta'))) + 1;
                if ($dias > CatalogoReportes::DIAS_MAXIMOS) {
                    $validator->errors()->add('hasta', 'El periodo no puede superar los '.CatalogoReportes::DIAS_MAXIMOS.' días.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'desde.required' => 'Indique la fecha inicial.',
            'desde.date_format' => 'La fecha inicial no es válida.',
            'hasta.required' => 'Indique la fecha final.',
            'hasta.date_format' => 'La fecha final no es válida.',
            'hasta.after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.',
            'medico.integer' => 'Seleccione un médico válido.',
            'especialidad.integer' => 'Seleccione una especialidad válida.',
            'modalidad.integer' => 'Seleccione una modalidad válida.',
            'metodo.integer' => 'Seleccione un método de pago válido.',
            'estado.in' => 'El estado de la cita no es válido.',
            'estado_pago.in' => 'El estado del pago no es válido.',
            'agrupar.in' => 'Ese reporte no se puede agrupar así.',
            'seccion.in' => 'Elija exportar el resumen agrupado o el detalle.',
        ];
    }

    /** Reporte de la ruta (la ruta ya restringe los valores a CatalogoReportes). */
    public function tipo(): string
    {
        return (string) $this->route('tipo');
    }

    /**
     * Filtros normalizados del reporte, solo los que admite.
     *
     * @return array{desde: string, hasta: string, agrupar: string, medico?: ?int, especialidad?: ?int, modalidad?: ?int, metodo?: ?int, estado?: ?string, estado_pago?: ?string}
     */
    public function filtros(): array
    {
        $definicion = CatalogoReportes::definicion($this->tipo());
        $enteros = ['medico', 'especialidad', 'modalidad', 'metodo'];

        $filtros = [
            'desde' => $this->input('desde'),
            'hasta' => $this->input('hasta'),
            'agrupar' => $this->input('agrupar', $definicion['agrupacion_por_defecto']),
        ];

        foreach ($definicion['filtros'] as $filtro) {
            $filtros[$filtro] = match (true) {
                in_array($filtro, $enteros, true) => $this->filled($filtro) ? $this->integer($filtro) : null,
                // RN05: los ingresos cuentan por defecto solo los pagos completados.
                $filtro === 'estado_pago' => $this->input('estado_pago', 'Completado') === self::TODOS ? null : $this->input('estado_pago', 'Completado'),
                default => $this->input($filtro) ?: null,
            };
        }

        return $filtros;
    }
}
