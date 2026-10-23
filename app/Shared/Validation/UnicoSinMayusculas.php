<?php

namespace App\Shared\Validation;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Unicidad sin distinguir mayúsculas ('Pediatría' = 'pediatría'): los índices
 * UNIQUE de PostgreSQL sí las distinguen. Usada por CU03 (roles) y CU07 (especialidades).
 *
 * La tabla y las columnas las define el código, nunca el usuario; el valor
 * comparado se envía como parámetro de la consulta.
 */
class UnicoSinMayusculas implements ValidationRule
{
    public function __construct(
        private readonly string $tabla,
        private readonly string $columna,
        private readonly string $mensaje,
        private readonly ?int $ignorarId = null,
        private readonly string $columnaId = 'id',
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $existe = DB::table($this->tabla)
            ->whereRaw("lower({$this->columna}) = lower(?)", [$value])
            ->when($this->ignorarId, fn ($query) => $query->where($this->columnaId, '!=', $this->ignorarId))
            ->exists();

        if ($existe) {
            $fail($this->mensaje);
        }
    }
}
