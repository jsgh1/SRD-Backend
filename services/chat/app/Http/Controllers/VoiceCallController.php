<?php

namespace App\Http\Controllers;

use App\Application\VoiceCallService;
use Illuminate\Http\Request;
use Srd\Access;

final class VoiceCallController
{
    public function __construct(private VoiceCallService $calls) {}

    private function principal(): array
    {
        abort_unless(request()->attributes->get('issuer') === 'gateway', 403);
        return Access::require('chat.read');
    }

    public function index(): array { return $this->calls->list($this->principal()); }
    public function start(string $conversationId): array { return $this->calls->start($this->principal(), $conversationId); }
    public function show(string $conversationId, string $callId): array
    {
        return $this->calls->show($this->principal(), $conversationId, $callId);
    }
    public function transition(Request $request, string $conversationId, string $callId): array
    {
        $data = $request->validate(['action' => 'required|in:accept,reject,end,fail']);
        return $this->calls->transition($this->principal(), $conversationId, $callId, $data['action']);
    }
    public function signals(Request $request, string $conversationId, string $callId): array
    {
        $data = $request->validate(['after' => 'sometimes|integer|min:0']);
        return $this->calls->signals($this->principal(), $conversationId, $callId, (int) ($data['after'] ?? 0));
    }
    public function signal(Request $request, string $conversationId, string $callId): array
    {
        abort_if(strlen($request->getContent()) > 32768, 413);
        $data = $request->validate([
            'kind' => 'required|in:offer,answer,candidate,mute',
            'payload' => 'required|array',
            'payload.type' => 'required_if:kind,offer,answer|in:offer,answer',
            'payload.sdp' => 'required_if:kind,offer,answer|string|max:24000',
            'payload.candidate' => 'required_if:kind,candidate|string|max:4096',
            'payload.sdpMid' => 'sometimes|nullable|string|max:256',
            'payload.sdpMLineIndex' => 'sometimes|nullable|integer|min:0|max:128',
            'payload.muted' => 'required_if:kind,mute|boolean',
        ]);
        abort_unless(($data['kind'] === 'candidate' && isset($data['payload']['candidate']))
            || ($data['kind'] === 'mute' && array_key_exists('muted', $data['payload']))
            || (in_array($data['kind'], ['offer', 'answer'], true) && ($data['payload']['type'] ?? null) === $data['kind']), 422);
        $payload = $data['kind'] === 'candidate'
            ? array_intersect_key($data['payload'], array_flip(['candidate', 'sdpMid', 'sdpMLineIndex']))
            : ($data['kind'] === 'mute' ? ['muted' => (bool) $data['payload']['muted']]
                : array_intersect_key($data['payload'], array_flip(['type', 'sdp'])));
        return $this->calls->signal($this->principal(), $conversationId, $callId, $data['kind'], $payload);
    }
}
