<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titulo }}</title>
</head>
{{-- CU20: correo de notificación al paciente, con el estilo monocromático del sistema. --}}
<body style="margin:0;padding:24px;background:#F4F4F5;font-family:Arial,Helvetica,sans-serif;color:#000000;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#FFFFFF;border:1px solid #E4E4E7;border-radius:6px;">
        <tr>
            <td style="padding:24px 28px;border-bottom:2px solid #000000;">
                <p style="margin:0;font-size:18px;font-weight:bold;">{{ $clinica['nombre'] }}</p>
                <p style="margin:4px 0 0;font-size:13px;color:#71717A;">{{ $titulo }}</p>
            </td>
        </tr>
        <tr>
            <td style="padding:24px 28px;font-size:15px;line-height:1.5;">
                <p style="margin:0 0 12px;">Hola, {{ $nombre }}:</p>
                <p style="margin:0 0 20px;white-space:pre-line;">{{ $mensaje }}</p>
                <a href="{{ $enlace }}" style="display:inline-block;padding:10px 18px;background:#000000;color:#FFFFFF;text-decoration:none;border-radius:3px;font-size:14px;font-weight:bold;">Ver mis notificaciones</a>
            </td>
        </tr>
        <tr>
            <td style="padding:16px 28px;font-size:12px;color:#71717A;border-top:1px solid #E4E4E7;">
                {{ $clinica['direccion'] }} · Tel. {{ $clinica['telefono'] }}<br>
                Puede elegir si recibe correos y con cuánta anticipación desde «Mi perfil → Preferencias».
            </td>
        </tr>
    </table>
</body>
</html>
