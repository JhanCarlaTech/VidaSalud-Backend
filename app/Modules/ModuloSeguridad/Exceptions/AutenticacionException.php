<?php

namespace App\Modules\ModuloSeguridad\Exceptions;

use App\Shared\Http\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Rechazos esperados del inicio de sesión (CU01, excepciones E2–E5).
 * Se responden al cliente con un mensaje seguro y no se escriben en el log.
 */
class AutenticacionException extends Exception
{
    public function __construct(string $mensaje, private readonly int $estado)
    {
        parent::__construct($mensaje);
    }

    /** E2: mensaje genérico, nunca revela si el correo existe (RN04). */
    public static function credencialesInvalidas(): self
    {
        return new self('Correo o contraseña incorrectos.', 401);
    }

    /** E3 */
    public static function cuentaInactiva(): self
    {
        return new self('Cuenta inactiva. Contacte con la administración.', 403);
    }

    /** E4 */
    public static function sinAcceso(): self
    {
        return new self('Su cuenta no tiene acceso habilitado.', 403);
    }

    /** E5 */
    public static function demasiadosIntentos(int $segundos): self
    {
        return new self("Demasiados intentos. Intente nuevamente en {$segundos} segundos.", 429);
    }

    public function report(): void
    {
        // Rechazo esperado: queda registrado en la bitácora, no en el log de errores.
    }

    public function render(): JsonResponse
    {
        return ApiResponse::error($this->getMessage(), $this->estado);
    }
}
