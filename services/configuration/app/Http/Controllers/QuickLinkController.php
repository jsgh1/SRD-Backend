<?php

namespace App\Http\Controllers;

use App\Application\QuickLinkService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Srd\Access;

final class QuickLinkController
{
    public function __construct(private QuickLinkService $service) {}

    public function index(): array
    {
        return ['data' => $this->service->read(Access::require('dashboard.read'))];
    }

    public function update(Request $request, string $scope): array
    {
        $common = $scope === 'organization';
        $p = Access::require($common ? 'organization.manage' : 'profile.write');
        $rules = [
            'version' => 'required|integer|min:0',
            'items' => 'present|array|list|max:3',
            'items.*' => 'required|array:function,label,label_en',
            'items.*.function' => ['required', 'string', 'distinct:strict', Rule::in(array_keys(QuickLinkService::FUNCTIONS))],
            'items.*.label' => 'required|string|min:1|max:40',
            'items.*.label_en' => 'required|string|min:1|max:40',
        ];
        if ($common) {
            $rules['mode'] = 'sometimes|required|in:common,personal';
        } else {
            $rules['common_version'] = 'required|integer|min:0';
            $rules['inherit'] = 'required|boolean';
        }
        return ['data' => $this->service->save($p, $request->validate($rules), $common)];
    }
}
