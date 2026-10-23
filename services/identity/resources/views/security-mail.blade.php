<!doctype html><html lang="{{ $language }}"><body style="margin:0;background:#edf1f7;font-family:Arial,sans-serif;color:#172b4d;padding:32px">
<div style="max-width:520px;margin:auto;background:white;border-radius:16px;padding:36px">
<p style="color:#245bce;font-weight:bold;letter-spacing:2px">SRD · {{ $language === 'en' ? 'ACCOUNT SECURITY' : 'SEGURIDAD DE LA CUENTA' }}</p>
<h1 style="font-size:26px">{{ $language === 'en' ? ($purpose === 'reset' ? 'Reset your password' : ($purpose === 'email_change' ? 'Confirm your new email' : 'Your sign-in code')) : ($purpose === 'reset' ? 'Restablece tu contraseña' : ($purpose === 'email_change' ? 'Confirma tu nuevo correo' : 'Tu código de acceso')) }}</h1>
<p>{{ $language === 'en' ? ($purpose === 'reset' ? 'We received a request to create a new password for your SRD account.' : ($purpose === 'email_change' ? 'Enter this code in SRD to confirm your new sign-in address.' : 'Enter this code in SRD to finish signing in.')) : ($purpose === 'reset' ? 'Recibimos una solicitud para crear una nueva contraseña de tu cuenta SRD.' : ($purpose === 'email_change' ? 'Escribe este código en SRD para confirmar tu nuevo correo de acceso.' : 'Escribe este código en SRD para terminar de iniciar sesión.')) }}</p>
@if($link)
<p>{{ $language === 'en' ? 'This link works once and expires in 15 minutes.' : 'Este enlace funciona una sola vez y vence en 15 minutos.' }}</p>
<p><a href="{{ $link }}" style="display:inline-block;background:#245bce;color:white;padding:16px;text-decoration:none;border-radius:8px">{{ $language === 'en' ? 'Create a new password' : 'Crear nueva contraseña' }}</a></p>
@else
<p>{{ $language === 'en' ? 'Enter this code in SRD. It expires in five minutes.' : 'Escribe este código en SRD. Vence en cinco minutos.' }}</p>
<p style="font-size:36px;font-weight:bold;letter-spacing:8px;text-align:center;background:#eef3ff;padding:22px;border-radius:10px">{{ $code }}</p>
@endif
<p style="color:#52617b;line-height:1.6">{{ $language === 'en' ? 'If you did not request this, ignore the message and contact your council administrator if you suspect unauthorized access. Never share codes or links. Check spam if an expected SRD email is missing.' : 'Si no hiciste esta solicitud, ignora el mensaje y contacta al administrador de tu junta si sospechas un acceso no autorizado. No compartas códigos ni enlaces. Revisa spam si no encuentras un correo esperado de SRD.' }}</p>
<p style="font-size:12px;color:#52617b">{{ $language === 'en' ? 'Digital Registry System · Community security' : 'Sistema de Registro Digital · Seguridad de la comunidad' }}</p></div></body></html>
