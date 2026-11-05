<!doctype html><html lang="{{ $language }}"><body style="margin:0;padding:24px;background:#f1f5f9;color:#183153;font-family:Arial,Helvetica,sans-serif">
<div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #d8e2ed;border-radius:14px;overflow:hidden">
<div style="padding:20px 32px;background:#173960;color:#fff;font-weight:700;letter-spacing:.08em">SRD · {{ $language === 'en' ? 'PRIVATE INVITATION' : 'INVITACIÓN PRIVADA' }}</div>
<div style="padding:32px">
<h1 style="font-size:25px;line-height:1.2;margin:0 0 14px">{{ $language === 'en' ? 'Join your council' : 'Únete a tu junta' }}</h1>
<p style="font-size:16px;line-height:1.6"><strong>{{ $organization }}</strong> {{ $language === 'en' ? 'invited you to access its SRD workspace.' : 'te invitó a acceder a su espacio de SRD.' }}</p>
<p style="padding:14px 18px;background:#f0f5fa;border-left:3px solid #245bce;border-radius:6px">{{ $language === 'en' ? 'Council code' : 'Código de junta' }}: <strong>{{ $organizationCode }}</strong></p>
<p style="margin:24px 0"><a href="{{ $link }}" style="display:inline-block;padding:14px 20px;border-radius:8px;background:#245bce;color:#fff;text-decoration:none;font-weight:700">{{ $language === 'en' ? 'Review invitation' : 'Revisar invitación' }}</a></p>
<p style="font-size:14px;line-height:1.55">{{ $language === 'en' ? 'The link works once and expires 24 hours after creation. If you already have an account, use its current password. Signing in still requires a security code.' : 'El enlace funciona una sola vez y vence 24 horas después de creada la invitación. Si ya tienes cuenta, usa tu contraseña actual. Para iniciar sesión también necesitarás un código de seguridad.' }}</p>
<p style="font-size:13px;line-height:1.55;color:#49617d">{{ $language === 'en' ? 'If you did not expect this invitation, ignore it. Never share the link. Check spam if you cannot find an expected SRD email.' : 'Si no esperabas esta invitación, ignórala. No compartas el enlace. Revisa spam si no encuentras un correo esperado de SRD.' }}</p>
</div></div></body></html>
