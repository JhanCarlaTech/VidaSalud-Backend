<?php

namespace App\Modules\ModuloSeguridad\Requests;

use App\Modules\ModuloSeguridad\Services\ImportLoteService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/importaciones/validar y POST /api/importaciones (CU04, E1).
 * El contenido del archivo lo valida ImportLoteService.
 *
 * Se usa 'extensions' (extensión del nombre) y no 'mimes', porque esta última
 * necesita la extensión 'fileinfo' de PHP.
 */
class ImportLoteRequest extends FormRequest
{
    /** RN01: tamaño máximo del archivo, en KB. */
    private const TAMANO_MAXIMO_KB = 2048;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::in(ImportLoteService::TIPOS)],
            'archivo' => ['required', 'file', 'extensions:csv,txt', 'max:'.self::TAMANO_MAXIMO_KB],
        ];
    }

    public function messages(): array
    {
        return [
            'tipo.required' => 'Seleccione el tipo de carga.',
            'tipo.in' => 'El tipo de carga debe ser personal o pacientes.',
            'archivo.required' => 'Seleccione el archivo a cargar.',
            'archivo.file' => 'No se pudo recibir el archivo. Intente de nuevo.',
            'archivo.extensions' => 'El archivo debe ser CSV (.csv o .txt). En Excel use "Guardar como → CSV UTF-8".',
            'archivo.max' => 'El archivo no puede superar los 2 MB.',
        ];
    }
}
