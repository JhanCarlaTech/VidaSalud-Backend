<?php

namespace App\Modules\ModuloOperacionCitas\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConsultaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id_cita_medica' => ['required', 'integer', 'exists:cita_medica,id'],
            'motivo_de_consultas' => ['required', 'string', 'max:2000'],
            'peso' => ['required', 'numeric', 'min:1', 'max:300'],
            'estatura' => ['required', 'numeric', 'min:30', 'max:250'],
            'escala_del_dolor' => ['nullable', 'integer', 'min:0', 'max:10'],
            'orden_cronologico' => ['nullable', 'string'],
            'sintomas' => ['nullable', 'string'],
            'tratamiento' => ['nullable', 'string'],
        ];
    }
}