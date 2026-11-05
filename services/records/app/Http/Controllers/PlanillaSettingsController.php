<?php

namespace App\Http\Controllers;

use App\Application\PlanillaSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Srd\Access;

final class PlanillaSettingsController
{
    public function publicLogo(Request $request, string $id): array
    {
        abort_unless($request->attributes->get('issuer') === 'gateway', 403);

        return ['data' => ['logo_data' => DB::table('planilla_settings')
            ->where('organization_id', $id)->value('logo_data')]];
    }

    public function index(PlanillaSettings $settings): array
    {
        return ['data' => $settings->read(Access::require('persons.read'))];
    }

    public function update(Request $request, PlanillaSettings $settings): array
    {
        $p = Access::require('persons.read');
        $data = $request->validate([
            'version' => 'required|integer|min:0',
            'allowed_columns' => 'present|array|list|max:8',
            'allowed_columns.*' => 'required|string|distinct:strict|in:'.implode(',', PlanillaSettings::COLUMNS),
            'h1' => 'required|string|max:120',
            'h2' => 'required|string|max:120',
            'h3' => 'required|string|max:120',
            'h1_en' => 'required|string|max:120',
            'h2_en' => 'required|string|max:120',
            'h3_en' => 'required|string|max:120',
            'logo_data' => 'sometimes|nullable|string|max:180000',
            'delegated_roles' => 'sometimes|array|list|max:4',
            'delegated_roles.*' => 'required|string|distinct:strict|in:registrar,treasurer,auditor,viewer',
        ]);
        return $settings->update($p, $data);
    }
}
