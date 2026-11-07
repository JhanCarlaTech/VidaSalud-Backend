<?php

namespace App\Modules\ModuloInteligenciaReportes\Services;

use App\Modules\ModuloInteligenciaReportes\Jobs\EnviarCorreoNotificacion;
use App\Modules\ModuloInteligenciaReportes\Jobs\EnviarPushNotificacion;
use App\Modules\ModuloInteligenciaReportes\Models\Notificacion;
use App\Modules\ModuloOperacionCitas\Models\CitaMedica;
use App\Modules\ModuloPacientes\Models\Paciente;
use App\Modules\ModuloSeguridad\Models\PreferenciaNotificacion;
use App\Modules\ModuloSeguridad\Models\Usuario;
use App\Modules\ModuloSeguridad\Services\BitacoraService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * CU20 – Gestionar Notificaciones y Recordatorios.
 */
class NotificacionService
{
    public function __construct(private readonly BitacoraService $bitacora) {}

    // --- Avisos automáticos de citas (CU13/CU14, RN02) --------------------------

    /**
     * Notifica al paciente que su cita se agendó, reprogramó o canceló. Lo llama
     * CitaService después de confirmar la operación; el correo sale tras responder.
     */
    public function notificarCita(CitaMedica $cita, string $tipo, ?string $motivo = null): Notificacion
    {
        $cita->loadMissing('medico.usuario');

        $mensaje = match ($tipo) {
            Notificacion::CITA_AGENDADA => "Su cita {$cita->codigo_cita} con {$this->medico($cita)} quedó agendada para el {$this->cuando($cita)}.",
            Notificacion::CITA_REPROGRAMADA => "Su cita {$cita->codigo_cita} con {$this->medico($cita)} se reprogramó para el {$this->cuando($cita)}.",
            Notificacion::CITA_CANCELADA => "Su cita {$cita->codigo_cita} con {$this->medico($cita)} del {$this->cuando($cita)} fue cancelada."
                .($motivo ? ' Motivo: '.rtrim($motivo, '. ').'.' : '').' Puede agendar una nueva cuando lo necesite.',
            Notificacion::RECORDATORIO => "Le recordamos su cita {$cita->codigo_cita} con {$this->medico($cita)} el {$this->cuando($cita)}."
                .' Si no podrá asistir, reprográmela o cancélela con anticipación.',
        };

        return $this->crear($cita->id_paciente, $cita->id, $tipo, $mensaje, enviarAhora: false);
    }

    /**
     * RN04: recordatorio de las citas Programadas o Confirmadas que empiezan dentro de la
     * anticipación elegida por cada paciente (CU05: 2, 12, 24 o 48 horas; 24 por defecto).
     * Cada cita recibe un solo recordatorio; si se reprograma, recibe otro para la nueva hora.
     * Lo ejecuta el planificador cada 15 minutos ('notificaciones:recordatorios').
     *
     * @return int Recordatorios creados.
     */
    public function enviarRecordatorios(): int
    {
        return DB::transaction(function () {
            // Evita que dos ejecuciones simultáneas (planificador y botón manual) dupliquen recordatorios.
            DB::select('select pg_advisory_xact_lock(?)', [crc32('cu20-recordatorios')]);

            $zona = config('app.timezone');
            $inicio = '((cita_medica.fecha + cita_medica.hra_inicio) at time zone ?)';

            $citas = CitaMedica::query()
                ->select('cita_medica.*')
                ->leftJoin('preferencia_notificacion as pref', 'pref.id_usuario', '=', 'cita_medica.id_paciente')
                ->whereIn('cita_medica.estado', [CitaMedica::PROGRAMADA, CitaMedica::CONFIRMADA])
                ->whereHas('paciente', fn (Builder $paciente) => $paciente->where('activo', true))
                ->whereRaw("{$inicio} > now()", [$zona])
                ->whereRaw("{$inicio} <= now() + make_interval(hours => coalesce(pref.horas_anticipacion, ?)::int)",
                    [$zona, PreferenciaNotificacion::POR_DEFECTO['horas_anticipacion']])
                ->whereNotExists(fn ($recordatorios) => $recordatorios->selectRaw('1')
                    ->from('notificacion as r')
                    ->whereColumn('r.id_cita', 'cita_medica.id')
                    ->where('r.tipo', Notificacion::RECORDATORIO)
                    ->whereRaw("r.fecha_envio >= coalesce((select max(n.fecha_envio) from notificacion n where n.id_cita = cita_medica.id and n.tipo = ?), '-infinity'::timestamptz)",
                        [Notificacion::CITA_REPROGRAMADA]))
                ->with('medico.usuario')
                ->get();

            foreach ($citas as $cita) {
                $notificacion = $this->notificarCita($cita, Notificacion::RECORDATORIO);
                // Tras confirmar la transacción, para que el correo vea la notificación guardada.
                DB::afterCommit(function () use ($notificacion) {
                    EnviarCorreoNotificacion::dispatchSync($notificacion->id);
                    EnviarPushNotificacion::dispatchSync($notificacion->id);
                });
            }

            return $citas->count();
        });
    }

    // --- Recepción (RN05–RN06) ------------------------------------------------

    /**
     * @param  array{buscar: ?string, tipo: ?string, estado_correo: ?string, desde: ?string, hasta: ?string}  $filtros
     */
    public function listar(array $filtros, int $porPagina): LengthAwarePaginator
    {
        return Notificacion::query()
            ->with(['paciente:id_usuario,codigo', 'paciente.usuario:id,ci,nombre,apellido', 'cita:id,codigo_cita,fecha,hra_inicio'])
            ->when($filtros['buscar'], function (Builder $query, string $termino) {
                $patron = '%'.addcslashes($termino, '\\%_').'%';

                $query->whereHas('paciente', fn (Builder $paciente) => $paciente
                    ->where('codigo', 'ilike', $patron)
                    ->orWhereHas('usuario', fn (Builder $usuario) => $usuario
                        ->where('ci', 'ilike', $patron)
                        ->orWhereRaw("(nombre || ' ' || apellido) ilike ?", [$patron])));
            })
            ->when($filtros['tipo'], fn (Builder $query, string $tipo) => $query->where('tipo', $tipo))
            ->when($filtros['estado_correo'], fn (Builder $query, string $estado) => $query->where('estado_correo', $estado))
            ->when($filtros['desde'], fn (Builder $query, string $desde) => $query->where('fecha_envio', '>=', CarbonImmutable::parse($desde)->startOfDay()->toIso8601String()))
            ->when($filtros['hasta'], fn (Builder $query, string $hasta) => $query->where('fecha_envio', '<', CarbonImmutable::parse($hasta)->addDay()->startOfDay()->toIso8601String()))
            ->orderByDesc('fecha_envio')
            ->orderByDesc('id')
            ->paginate($porPagina);
    }

    /**
     * RN05: aviso escrito por Recepción, opcionalmente sobre una cita del paciente.
     *
     * @throws ValidationException
     */
    public function enviarAviso(Paciente $paciente, ?int $idCita, string $mensaje, Usuario $actor): Notificacion
    {
        if ($idCita !== null && ! CitaMedica::query()->whereKey($idCita)->where('id_paciente', $paciente->id_usuario)->exists()) {
            throw ValidationException::withMessages(['id_cita' => 'La cita no pertenece al paciente seleccionado.']);
        }

        if (! $paciente->activo) {
            throw ValidationException::withMessages(['id_paciente' => 'El paciente está inactivo.']);
        }

        $notificacion = $this->crear($paciente->id_usuario, $idCita, Notificacion::AVISO, $mensaje, enviarAhora: true);

        $this->bitacora->registrar('INSERT', 'notificacion', $actor->id, datosNuevos: [
            'id' => $notificacion->id,
            'paciente' => $paciente->codigo,
            'tipo' => Notificacion::AVISO,
            'id_cita' => $idCita,
        ]);

        return $notificacion->refresh();
    }

    /**
     * RN06: vuelve a intentar el correo de una notificación pendiente o fallida.
     *
     * @throws ValidationException
     */
    public function reenviar(Notificacion $notificacion, Usuario $actor): Notificacion
    {
        if (! in_array($notificacion->estado_correo, [Notificacion::CORREO_PENDIENTE, Notificacion::CORREO_FALLIDO], true)) {
            throw ValidationException::withMessages(['notificacion' => $notificacion->estado_correo === Notificacion::CORREO_ENVIADO
                ? 'El correo de esta notificación ya se envió.'
                : 'El paciente no recibe correos (no tiene correo o los desactivó en sus preferencias); la notificación está en su bandeja.']);
        }

        EnviarCorreoNotificacion::dispatchSync($notificacion->id);

        $this->bitacora->registrar('REENVIO', 'notificacion', $actor->id, datosNuevos: [
            'id' => $notificacion->id,
            'estado_correo' => $notificacion->refresh()->estado_correo,
        ]);

        return $notificacion;
    }

    // --- Bandeja del paciente (RN07) -----------------------------------------------

    public function bandeja(Paciente $paciente, bool $soloNoLeidas, int $porPagina): LengthAwarePaginator
    {
        return Notificacion::query()
            ->with('cita:id,codigo_cita,fecha,hra_inicio,estado')
            ->where('id_paciente', $paciente->id_usuario)
            ->when($soloNoLeidas, fn (Builder $query) => $query->where('leida', false))
            ->orderByDesc('fecha_envio')
            ->orderByDesc('id')
            ->paginate($porPagina);
    }

    public function noLeidas(Paciente $paciente): int
    {
        return Notificacion::query()->where('id_paciente', $paciente->id_usuario)->where('leida', false)->count();
    }

    public function marcarLeida(Notificacion $notificacion): Notificacion
    {
        if (! $notificacion->leida) {
            $notificacion->update(['leida' => true, 'fecha_lectura' => now()]);
        }

        return $notificacion;
    }

    /** @return int Notificaciones marcadas. */
    public function marcarTodasLeidas(Paciente $paciente): int
    {
        return Notificacion::query()
            ->where('id_paciente', $paciente->id_usuario)
            ->where('leida', false)
            ->update(['leida' => true, 'fecha_lectura' => now()]);
    }

    // --- Apoyo ------------------------------------------------------------------

    /**
     * Guarda la notificación (siempre visible en la bandeja) y programa su correo:
     * enseguida (aviso manual, para informar el resultado) o al terminar la respuesta.
     */
    private function crear(int $idPaciente, ?int $idCita, string $tipo, string $mensaje, bool $enviarAhora): Notificacion
    {
        $notificacion = Notificacion::create([
            'id_paciente' => $idPaciente,
            'id_cita' => $idCita,
            'tipo' => $tipo,
            'mensaje' => $mensaje,
            'estado_correo' => Notificacion::CORREO_PENDIENTE,
        ]);

        if ($tipo !== Notificacion::RECORDATORIO) {
            $enviarAhora
                ? EnviarCorreoNotificacion::dispatchSync($notificacion->id)
                : EnviarCorreoNotificacion::dispatchAfterResponse($notificacion->id);
            // Push al dispositivo del paciente: nunca hace esperar la respuesta.
            EnviarPushNotificacion::dispatchAfterResponse($notificacion->id);
        }

        return $notificacion;
    }

    /** 'Dr(a). Juan Pérez' */
    private function medico(CitaMedica $cita): string
    {
        return "Dr(a). {$cita->medico->usuario->nombre} {$cita->medico->usuario->apellido}";
    }

    /** 'viernes 27 de noviembre de 2026 a las 08:30' */
    private function cuando(CitaMedica $cita): string
    {
        $fecha = CarbonImmutable::parse($cita->fecha->toDateString())->locale('es');

        return $fecha->translatedFormat('l j \d\e F \d\e Y').' a las '.substr($cita->hra_inicio, 0, 5);
    }
}
