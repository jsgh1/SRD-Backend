<?php

namespace App\Http\Controllers;

use App\Support\ChatConnectionTerminator;
use Illuminate\Http\Request;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Srd\InternalClient;
use Srd\Access;

final class GatewayController
{
    public function __construct(private InternalClient $client, private ChatConnectionTerminator $chatConnections) {}

    private function principal(Request $r, bool $allowTerms = false, bool $touchActivity = true): array
    {
        abort_unless($r->session()->has('credential'), 401);
        $p = $this->client->call('identity', 'POST', 'auth/me', ['token' => $r->session()->get('credential'), 'touch_activity' => $touchActivity]);
        abort_unless($allowTerms || ! $p['terms_required'], 403);

        return $p;
    }

    public function publicConfig(string $code): array
    {
        abort_unless(preg_match('/^[a-z0-9-]{3,40}$/', $code), 404);

        $organization = $this->client->call('configuration', 'GET', 'organizations/code/'.$code);
        $logo = null;
        try {
            $logo = $this->client->call('records', 'GET', 'public-logo/'.$organization['id'], [], [], 2)['logo_data'] ?? null;
        } catch (\Throwable) {
            // A Records outage must not prevent access to the council login.
        }

        return ['data' => $organization + ['logo_data' => $logo]];
    }

    public function terminateChatSession(Request $r): array
    {
        abort_unless($r->attributes->get('issuer') === 'identity', 403);
        $data = $r->validate(['session_id' => 'required|uuid']);
        abort_unless($this->chatConnections->terminate($data['session_id']), 503);

        return ['data' => []];
    }

    public function auth(Request $r, string $action): array
    {
        abort_unless(in_array($action, ['login', 'verify', 'resend', 'cancel', 'recover', 'reset', 'logout', 'revokeOthers', 'switchOrganization'], true), 404);
        $data = $r->except(['token', 'organization_id', 'role', 'principal']);
        $principal = null;
        if (in_array($action, ['logout', 'revokeOthers', 'switchOrganization'], true)) {
            $principal = $this->principal($r, true);
            $data['token'] = $r->session()->get('credential');
        }
        if (in_array($action, ['verify', 'resend', 'cancel'], true)) {
            abort_unless($r->session()->get('challenge_id') === ($data['challenge_id'] ?? null), 422);
        }
        // Password verification and local mail delivery can exceed the default internal timeout.
        $timeout = in_array($action, ['login', 'resend', 'recover'], true) ? 30 : null;
        $result = $this->client->call('identity', 'POST', 'auth/'.$action, $data, [], $timeout);
        if (in_array($action, ['logout', 'switchOrganization'], true)
            && is_string($principal['session_id'] ?? null)) {
            $this->chatConnections->terminate($principal['session_id']);
        }
        if (in_array($action, ['revokeOthers', 'reset'], true)) {
            foreach ($result['revoked_session_ids'] ?? [] as $sessionId) {
                if (is_string($sessionId)) $this->chatConnections->terminate($sessionId);
            }
            // Internal session IDs are only needed to close sockets, never by the browser.
            $result = [];
        }
        if (in_array($action, ['login', 'resend'], true)) {
            $r->session()->put('challenge_id', $result['challenge_id']);
        }
        if ($action === 'verify') {
            $r->session()->regenerate();
            $r->session()->put('credential', $result['token']);
            $r->session()->forget('challenge_id');

            return ['data' => $this->principal($r)];
        }
        if ($action === 'switchOrganization') {
            $r->session()->regenerate();
            $r->session()->put('credential', $result['token']);
            $result = [];
        }
        if ($action === 'cancel') {
            $r->session()->forget('challenge_id');
        }
        if ($action === 'logout') {
            $r->session()->invalidate();
            $r->session()->regenerateToken();
        }

        return ['data' => $result];
    }

    public function me(Request $r): array
    {
        return ['data' => $this->principal($r, true)];
    }

    public function serviceStatus(Request $r): array
    {
        $principal = $this->principal($r);
        abort_unless(in_array($principal['role'], ['superadmin', 'admin'], true), 403);
        $services = ['identity', 'configuration', 'records', 'files', 'audit', 'calendar',
            'notifications', 'treasury', 'inventory', 'chat'];
        $targets = [];
        foreach ($services as $service) {
            $url = config('srd.urls.'.$service);
            if (is_string($url) && $url !== '') $targets[$service] = rtrim($url, '/').'/up';
        }
        try {
            $responses = $targets === [] ? [] : Http::pool(function (Pool $pool) use ($targets) {
                foreach ($targets as $service => $url)
                    $pool->as($service)->connectTimeout(1)->timeout(2)->get($url);
            }, count($targets));
        } catch (\Throwable) {
            // A pool failure must still return a bounded degraded status.
            $responses = [];
        }
        $items = array_map(static fn ($service) => ['service' => $service,
            'available' => ($responses[$service] ?? null) instanceof Response
                && $responses[$service]->successful()], $services);
        $antivirusAvailable = false;
        if ($items[3]['available']) {
            try {
                $status = $this->client->call('files', 'GET', 'antivirus-status');
                $antivirusAvailable = ($status['available'] ?? null) === true;
            } catch (\Throwable) {
                // The domain response must remain available even if this dependency fails.
            }
        }
        return ['data' => ['items' => $items, 'antivirus_available' => $antivirusAvailable,
            'checked_at' => now()->toIso8601String()]];
    }

    public function schedulerStatus(Request $r): array
    {
        // Passive monitoring must not extend the user's idle session.
        $principal = $this->principal($r, false, false);
        abort_unless(in_array($principal['role'], ['superadmin', 'admin'], true), 403);
        $services = ['identity', 'configuration', 'records', 'files', 'calendar',
            'notifications', 'treasury', 'inventory', 'chat'];
        $targets = [];
        foreach ($services as $service) {
            $url = config('srd.urls.'.$service);
            if (is_string($url) && $url !== '') {
                $targets[$service] = rtrim($url, '/').'/internal/v1/scheduler-status';
            }
        }
        try {
            $responses = $targets === [] ? [] : Http::pool(function (Pool $pool) use ($targets) {
                foreach ($targets as $service => $url) {
                    $pool->as($service)->connectTimeout(1)->timeout(2)->acceptJson()
                        ->withHeaders($this->client->signedHeaders($service, 'GET', '/internal/v1/scheduler-status'))
                        ->get($url);
                }
            }, count($targets));
        } catch (\Throwable) {
            $responses = [];
        }
        $items = array_map(static function ($service) use ($responses) {
            $response = $responses[$service] ?? null;
            $body = $response instanceof Response && $response->successful() ? $response->json('data') : null;
            $cycle = is_array($body) && ($body['cycle_recent'] ?? null) === true;
            $outbox = is_array($body) && ($body['outbox_recent'] ?? null) === true;

            return ['service' => $service, 'available' => $cycle && $outbox,
                'cycle_recent' => $cycle, 'outbox_recent' => $outbox];
        }, $services);

        return ['data' => ['items' => $items, 'checked_at' => now()->toIso8601String()]];
    }

    public function auditDeliveryOverview(Request $r): array
    {
        $principal = $this->principal($r, false, false);
        abort_unless(in_array($principal['role'], ['superadmin', 'admin'], true), 403);
        $context = array_intersect_key($principal, array_flip(['user_id', 'organization_id', 'session_id', 'role', 'membership_id', 'membership_version']));
        $services = ['identity', 'configuration', 'records', 'files', 'calendar',
            'notifications', 'treasury', 'inventory', 'chat'];
        $targets = [];
        foreach ($services as $service) {
            $url = config('srd.urls.'.$service);
            if (is_string($url) && $url !== '') $targets[$service] = rtrim($url, '/').'/internal/v1/outbox-status';
        }
        try {
            $responses = $targets === [] ? [] : Http::pool(function (Pool $pool) use ($targets, $context) {
                foreach ($targets as $service => $url) {
                    $pool->as($service)->connectTimeout(1)->timeout(3)->acceptJson()
                        ->withHeaders($this->client->signedHeaders($service, 'GET', '/internal/v1/outbox-status', '', $context))
                        ->get($url);
                }
            }, count($targets));
        } catch (\Throwable) {
            $responses = [];
        }
        $items = array_map(static function ($service) use ($responses) {
            $response = $responses[$service] ?? null;
            $body = $response instanceof Response && $response->successful() ? $response->json('data') : null;
            $exhausted = is_array($body) ? ($body['exhausted'] ?? null) : null;
            return ['service' => $service, 'available' => is_int($exhausted) && $exhausted >= 0,
                'exhausted' => is_int($exhausted) && $exhausted >= 0 ? $exhausted : null];
        }, $services);

        return ['data' => ['items' => $items, 'checked_at' => now()->toIso8601String()]];
    }

    public function presence(Request $r): array
    {
        abort_unless($r->session()->has('credential'), 401);
        // Do not call principal(): automatic traffic must not renew idle time.
        return ['data' => $this->client->call('identity', 'POST', 'auth/presence', ['token' => $r->session()->get('credential')])];
    }

    public function chatBroadcastConfig(Request $r): array
    {
        $p = $this->principal($r, false, false);
        abort_unless(Access::allows($p['role'], 'chat.read'), 403);
        $key = config('srd.chat_broadcast_key');
        abort_unless(is_string($key) && $key !== '', 503);
        return ['data' => ['key' => $key,
            'channel' => 'presence-chat.'.$p['organization_id'].'.'.$p['user_id']]];
    }

    public function voiceIceConfig(Request $r): array
    {
        $p = $this->principal($r, false, false);
        abort_unless(Access::allows($p['role'], 'chat.read'), 403);
        $servers = [];
        $stun = config('srd.webrtc_stun_url');
        if (is_string($stun) && preg_match('~^stuns?:[^\s]{1,240}$~', $stun)) {
            $servers[] = ['urls' => $stun];
        }
        $turn = config('srd.webrtc_turn_url');
        $secret = config('srd.webrtc_turn_secret');
        if (is_string($turn) && preg_match('~^turns?:[^\s]{1,240}$~', $turn)
            && is_string($secret) && strlen($secret) >= 32) {
            $username = (time() + 3600).':'.$p['user_id'];
            $servers[] = ['urls' => $turn, 'username' => $username,
                'credential' => base64_encode(hash_hmac('sha1', $username, $secret, true))];
        }
        return ['data' => ['iceServers' => $servers]];
    }

    public function chatBroadcastAuthorize(Request $r): array
    {
        $p = $this->principal($r, false, false);
        abort_unless(Access::allows($p['role'], 'chat.read'), 403);
        $data = $r->validate(['socket_id' => ['required', 'regex:/^[0-9]+\\.[0-9]+$/'],
            'channel_name' => ['required', 'string', 'max:128']]);
        $channel = 'presence-chat.'.$p['organization_id'].'.'.$p['user_id'];
        abort_unless(hash_equals($channel, $data['channel_name']), 403);
        $key = config('srd.chat_broadcast_key');
        $secret = config('srd.chat_broadcast_secret');
        abort_unless(is_string($key) && $key !== '' && is_string($secret) && $secret !== '', 503);
        $sessionId = $p['session_id'] ?? null;
        abort_unless(is_string($sessionId) && preg_match('/^[0-9a-fA-F-]{36}$/', $sessionId), 403);
        $channelData = json_encode(['user_id' => $sessionId], JSON_THROW_ON_ERROR);
        return ['data' => ['auth' => $key.':'.hash_hmac('sha256',
            $data['socket_id'].':'.$channel.':'.$channelData, $secret), 'channel_data' => $channelData]];
    }

    public function invitation(Request $r, string $action): array
    {
        abort_unless(in_array($action, ['inspect', 'accept'], true), 404);

        return ['data' => $this->client->call('identity', 'POST', 'invitations/'.$action,
            $r->only(['invitation_id', 'secret', 'name', 'password', 'password_confirmation', 'terms_version_id', 'accepted']))];
    }

    public function forward(Request $r, string $service, string $path): array
    {
        $passiveRead = $r->isMethod('GET') && (($service === 'identity' && $path === 'contacts')
            || ($service === 'identity' && $path === 'mail-delivery-status')
            || ($service === 'calendar' && $path === 'delivery-status')
            || ($service === 'notifications' && $path === 'notifications')
            || ($service === 'chat' && ($path === 'conversations' || $path === 'voice-calls'
                || preg_match('~^conversations/[0-9a-fA-F-]{36}/messages$~', $path)
                || preg_match('~^conversations/[0-9a-fA-F-]{36}/calls/[0-9a-fA-F-]{36}(/signals)?$~', $path))));
        $p = $this->principal($r, false, !$passiveRead);
        $context = array_intersect_key($p, array_flip(['user_id', 'organization_id', 'session_id', 'role', 'membership_id', 'membership_version']));
        if (in_array($service, ['treasury', 'inventory', 'chat'], true)) $context['name'] = $p['user']['name'];
        $data = $r->except(['organization_id', 'user_id', 'role', 'principal', 'token']);
        // This is the target membership's role, never the caller's authority.
        if ($service === 'identity' && (($path === 'invitations' && $r->isMethod('POST')) || (str_starts_with($path, 'members/') && $r->isMethod('PATCH')))) {
            $data['role'] = $r->input('role');
        }
        // Here user_id denotes the chosen contact, never the authenticated sender.
        if ($service === 'chat' && $path === 'conversations' && $r->isMethod('POST')) {
            $data['user_id'] = $r->input('user_id');
        }

        $attachmentTimeout = $service === 'chat' && $r->isMethod('POST')
            && preg_match('~^conversations/[0-9a-fA-F-]{36}/messages$~', $path)
            && isset($data['attachment']) ? 90
            : ($service === 'chat' && $r->isMethod('GET')
                && preg_match('~^conversations/[0-9a-fA-F-]{36}/messages/[0-9a-fA-F-]{36}/attachment$~', $path) ? 75 : null);
        $result = $this->client->call($service, $r->method(), $path, $data, $context, $attachmentTimeout);
        if ($service === 'identity' && $r->isMethod('PATCH')
            && (preg_match('~^members/[0-9a-fA-F-]{36}$~', $path)
                || preg_match('~^platform/accounts/[0-9a-fA-F-]{36}$~', $path))) {
            foreach ($result['revoked_session_ids'] ?? [] as $sessionId) {
                if (is_string($sessionId)) $this->chatConnections->terminate($sessionId);
            }
            unset($result['revoked_session_ids']);
        }
        if ($service === 'identity' && $r->isMethod('POST')
            && $path === 'profile/email-change/confirm') {
            foreach ($result['revoked_session_ids'] ?? [] as $sessionId) {
                if (is_string($sessionId)) $this->chatConnections->terminate($sessionId);
            }
            unset($result['revoked_session_ids']);
        }

        return ['data' => $result];
    }

    public function folderAccessUpdate(Request $r): array
    {
        $p = $this->principal($r);
        abort_unless(Access::allows($p['role'], 'folders.manage'), 403);
        $validated = $r->validate(['reader_memberships' => 'sometimes|array|list|max:20',
            'reader_memberships.*' => 'required|uuid|distinct:strict']);
        $data = $r->except(['organization_id', 'user_id', 'role', 'principal', 'token', 'membership_id', 'membership_version']);
        if (array_key_exists('reader_memberships', $validated)) {
            $checked = $this->client->call('identity', 'POST', 'members/folder-readers/verify',
                ['ids' => $validated['reader_memberships']],
                array_intersect_key($p, array_flip(['user_id', 'organization_id', 'session_id', 'role'])));
            $data['reader_memberships'] = $checked['items'];
        }
        $context = array_intersect_key($p, array_flip(['user_id', 'organization_id', 'session_id', 'role', 'membership_id', 'membership_version']));
        return ['data' => $this->client->call('files', 'PUT', 'folder-access', $data, $context)];
    }
}
