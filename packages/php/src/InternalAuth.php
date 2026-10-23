<?php

namespace Srd;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class InternalAuth
{
    public function handle(Request $request, Closure $next)
    {
        [$encoded,$signature] = array_pad(explode('.', $request->header('X-SRD-Context', ''), 2), 2, '');
        $key = config('srd.internal_key');
        abort_unless(is_string($key) && strlen($key) >= 32 && hash_equals(hash_hmac('sha256', $encoded, $key), $signature), 401);
        $claims = json_decode(base64_decode($encoded, true) ?: '', true);
        abort_unless(is_array($claims) && ($claims['aud'] ?? '') === config('srd.service')
            && ($claims['exp'] ?? 0) >= time() && ($claims['iat'] ?? 0) <= time() + 5
            && ($claims['exp'] - $claims['iat']) <= 60
            && ($claims['method'] ?? '') === $request->method()
            && ($claims['path'] ?? '') === $request->getPathInfo()
            && hash_equals($claims['hash'] ?? '', hash('sha256', $request->getContent())), 401);
        // Files may ask Records for a minimal decision and publish its audit events.
        // It must not inherit access to person details, notes or other internal APIs.
        $filesProbe = ($claims['iss'] ?? '') === 'files'
            && config('srd.service') === 'records'
            && $request->method() === 'POST'
            && preg_match('#^/internal/v1/persons/[0-9a-fA-F-]{36}/photo-access$#D', $request->getPathInfo()) === 1;
        $filesAssetProbe = ($claims['iss'] ?? '') === 'files'
            && config('srd.service') === 'inventory'
            && $request->method() === 'POST'
            && preg_match('#^/internal/v1/assets/[0-9a-fA-F-]{36}/photo-access$#D', $request->getPathInfo()) === 1;
        $filesUserProbe = ($claims['iss'] ?? '') === 'files'
            && config('srd.service') === 'identity'
            && $request->method() === 'POST'
            && preg_match('#^/internal/v1/users/[0-9a-fA-F-]{36}/photo-access$#D', $request->getPathInfo()) === 1;
        $filesAudit = ($claims['iss'] ?? '') === 'files' && config('srd.service') === 'audit'
            && $request->method() === 'POST' && $request->getPathInfo() === '/internal/v1/events';
        $calendarAudit = ($claims['iss'] ?? '') === 'calendar' && ($claims['aud'] ?? '') === 'audit' && ($claims['method'] ?? '') === 'POST' && ($claims['path'] ?? '') === '/internal/v1/events';
        $calendarMembers = ($claims['iss'] ?? '') === 'calendar' && ($claims['aud'] ?? '') === 'identity'
            && ($claims['method'] ?? '') === 'POST' && ($claims['path'] ?? '') === '/internal/v1/calendar-participants/resolve';
        $chatContact = ($claims['iss'] ?? '') === 'chat' && ($claims['aud'] ?? '') === 'identity'
            && ($claims['method'] ?? '') === 'POST' && ($claims['path'] ?? '') === '/internal/v1/chat-contacts/resolve';
        $chatFiles = ($claims['iss'] ?? '') === 'chat' && ($claims['aud'] ?? '') === 'files'
            && (($claims['method'] ?? '') === 'POST' && ($claims['path'] ?? '') === '/internal/v1/chat-attachments'
                || ($claims['method'] ?? '') === 'GET' && preg_match('#^/internal/v1/chat-attachments/[0-9a-fA-F-]{36}$#D', $claims['path'] ?? '') === 1);
        $filesChatReference = ($claims['iss'] ?? '') === 'files' && ($claims['aud'] ?? '') === 'chat'
            && ($claims['method'] ?? '') === 'GET'
            && preg_match('#^/internal/v1/chat-attachment-references/[0-9a-fA-F-]{36}$#D', $claims['path'] ?? '') === 1;
        $chatAudit = ($claims['iss'] ?? '') === 'chat' && ($claims['aud'] ?? '') === 'audit'
            && ($claims['method'] ?? '') === 'POST' && ($claims['path'] ?? '') === '/internal/v1/events';
        $calendarDelivery = ($claims['iss'] ?? '') === 'calendar' && ($claims['aud'] ?? '') === 'notifications'
            && ($claims['method'] ?? '') === 'POST' && ($claims['path'] ?? '') === '/internal/v1/deliveries';
        $chatDelivery = ($claims['iss'] ?? '') === 'chat' && ($claims['aud'] ?? '') === 'notifications'
            && ($claims['method'] ?? '') === 'POST' && ($claims['path'] ?? '') === '/internal/v1/deliveries';
        $notificationsAudit = ($claims['iss'] ?? '') === 'notifications' && ($claims['aud'] ?? '') === 'audit'
            && ($claims['method'] ?? '') === 'POST' && ($claims['path'] ?? '') === '/internal/v1/events';
        $treasuryAudit = ($claims['iss'] ?? '') === 'treasury' && ($claims['aud'] ?? '') === 'audit'
            && ($claims['method'] ?? '') === 'POST' && ($claims['path'] ?? '') === '/internal/v1/events';
        $inventoryAudit = ($claims['iss'] ?? '') === 'inventory' && ($claims['aud'] ?? '') === 'audit'
            && ($claims['method'] ?? '') === 'POST' && ($claims['path'] ?? '') === '/internal/v1/events';
        abort_unless($filesProbe || $filesAssetProbe || $filesUserProbe || $filesAudit || $filesChatReference || $calendarAudit || $calendarMembers || $calendarDelivery || $chatDelivery || $chatContact || $chatFiles || $chatAudit || $notificationsAudit || $treasuryAudit || $inventoryAudit || in_array($claims['iss'] ?? '', ['gateway', 'identity', 'configuration', 'records', 'audit'], true), 401);
        abort_unless(Cache::add('internal-nonce:'.hash('sha256', $claims['iss'].($claims['nonce'] ?? '')), true, 65), 401);
        $request->attributes->set('principal', $claims['context'] ?? []);
        $request->attributes->set('issuer', $claims['iss']);

        return $next($request);
    }
}
