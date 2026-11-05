<?php

namespace App\Http\Controllers;

use App\Application\ChatService;
use Illuminate\Http\Request;
use Srd\Access;

final class ChatController
{
    public function __construct(private ChatService $chat) {}

    private function principal(): array
    {
        abort_unless(request()->attributes->get('issuer') === 'gateway', 403);
        return Access::require('chat.read');
    }

    public function index(Request $request): array
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1|max:100000']);
        return $this->chat->list($this->principal(), (int) ($data['page'] ?? 1));
    }

    public function show(string $id): array
    {
        return $this->chat->show($this->principal(), $id);
    }

    public function activity(Request $request, string $id): array
    {
        $data = $request->validate(['kind' => 'required|in:typing,recording,idle']);
        return $this->chat->activity($this->principal(), $id, $data['kind']);
    }

    public function start(Request $request): array
    {
        $data = $request->validate(['user_id' => 'required|uuid']);
        return $this->chat->start($this->principal(), $data['user_id']);
    }

    public function messages(Request $request, string $id): array
    {
        $data = $request->validate([
            'before' => 'sometimes|integer|min:1', 'after' => 'sometimes|integer|min:0',
            'receipt_ids' => 'sometimes|array|max:25', 'receipt_ids.*' => 'required|uuid|distinct',
            'visible_ids' => 'sometimes|array|max:100', 'visible_ids.*' => 'required|uuid|distinct',
        ]);
        if (isset($data['before'], $data['after'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['after' => 'Elige antes o después, no ambos.']);
        }
        return $this->chat->messages($this->principal(), $id,
            isset($data['before']) ? (int) $data['before'] : null,
            isset($data['after']) ? (int) $data['after'] : null,
            $data['receipt_ids'] ?? [], $data['visible_ids'] ?? []);
    }

    public function send(Request $request, string $id): array
    {
        abort_if(strlen($request->getContent()) > 14 * 1024 * 1024 + 4096, 413);
        $data = $request->validate([
            'client_id' => 'required|uuid', 'body' => 'present|nullable|string|max:10000',
            'reply_to_id' => 'sometimes|nullable|uuid',
            'attachment' => 'sometimes|array:name,content',
            'attachment.name' => 'required_with:attachment|string|max:255',
            'attachment.content' => 'required_with:attachment|string|max:13981016',
        ]);
        $data['body'] = (string) ($data['body'] ?? '');
        return $this->chat->send($this->principal(), $id, $data);
    }

    public function search(Request $request, string $id): array
    {
        $data = $request->validate(['q' => 'required|string|min:2|max:120']);
        return $this->chat->search($this->principal(), $id, $data['q']);
    }

    public function pinned(string $id): array
    {
        return $this->chat->pinned($this->principal(), $id);
    }

    public function pin(string $id, string $messageId): array
    {
        return $this->chat->pin($this->principal(), $id, $messageId);
    }

    public function hide(string $id, string $messageId): array
    {
        return $this->chat->hide($this->principal(), $id, $messageId);
    }

    public function clear(string $id): array
    {
        return $this->chat->clear($this->principal(), $id);
    }

    public function attachment(string $id, string $messageId): array
    {
        return $this->chat->attachment($this->principal(), $id, $messageId);
    }

    public function receipt(Request $request, string $id): array
    {
        $data = $request->validate([
            'kind' => 'required|in:delivered,read',
            'message_ids' => 'required|array|min:1|max:25',
            'message_ids.*' => 'required|uuid|distinct',
        ]);
        return $this->chat->confirmReceipt($this->principal(), $id, $data['kind'], $data['message_ids']);
    }
}
