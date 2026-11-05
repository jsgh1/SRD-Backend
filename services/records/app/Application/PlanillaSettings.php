<?php

namespace App\Application;

use Illuminate\Support\Facades\DB;
use Srd\Access;
use Srd\Outbox;
use Illuminate\Validation\ValidationException;

final class PlanillaSettings
{
    public const COLUMNS = ['email', 'phone', 'property_name', 'zone', 'position_label',
        'descriptive_role', 'status', 'affiliated'];

    public function read(array $p, bool $lock = false): array
    {
        if ($lock) DB::table('planilla_settings')->upsert([[
            'organization_id' => $p['organization_id'], 'version' => 0,
            'allowed_columns' => json_encode(self::COLUMNS, JSON_THROW_ON_ERROR),
            'h1' => 'JUNTA DE ACCIÓN COMUNAL', 'h2' => '', 'h3' => 'PLANILLA DE FIRMAS',
            'h1_en' => 'COMMUNITY ACTION BOARD', 'h2_en' => '', 'h3_en' => 'SIGNATURE SHEET',
            'delegated_roles' => '[]', 'logo_data' => null, 'created_at' => now(), 'updated_at' => now(),
        ]], ['organization_id'], ['organization_id']);
        $query = DB::table('planilla_settings')->where('organization_id', $p['organization_id']);
        if ($lock) $query->lockForUpdate();
        $row = $query->first();
        $settings = $row ? [
            'version' => (int) $row->version,
            'allowed_columns' => json_decode($row->allowed_columns, true, 512, JSON_THROW_ON_ERROR),
            'h1' => $row->h1, 'h2' => $row->h2, 'h3' => $row->h3,
            'h1_en' => $row->h1_en, 'h2_en' => $row->h2_en, 'h3_en' => $row->h3_en,
            'logo_data' => $row->logo_data,
            'delegated_roles' => json_decode($row->delegated_roles, true, 512, JSON_THROW_ON_ERROR),
        ] : [
            'version' => 0, 'allowed_columns' => self::COLUMNS,
            'h1' => 'JUNTA DE ACCIÓN COMUNAL', 'h2' => '', 'h3' => 'PLANILLA DE FIRMAS',
            'h1_en' => 'COMMUNITY ACTION BOARD', 'h2_en' => '', 'h3_en' => 'SIGNATURE SHEET',
            'logo_data' => null,
            'delegated_roles' => [],
        ];
        $settings['can_delegate'] = Access::allows($p['role'], 'organization.manage');
        $settings['can_manage'] = $settings['can_delegate'] || in_array($p['role'], $settings['delegated_roles'], true);
        return $settings;
    }

    public function update(array $p, array $data): array
    {
        return DB::transaction(function () use ($p, $data) {
            $old = $this->read($p, true);
            abort_unless($old['can_manage'], 403);
            abort_if(array_key_exists('delegated_roles', $data) && !$old['can_delegate'], 403);
            abort_unless($old['version'] === (int) $data['version'], 409, 'La configuración de la planilla cambió. Recarga antes de guardar.');
            if (array_key_exists('logo_data', $data) && $data['logo_data'] !== null) {
                $logo = $data['logo_data'];
                $encoded = str_starts_with($logo, 'data:image/png;base64,') ? substr($logo, 22) : '';
                $bytes = base64_decode($encoded, true);
                $image = $bytes !== false && strlen($bytes) <= 131072 && str_starts_with($bytes, "\x89PNG\r\n\x1a\n")
                    ? @getimagesizefromstring($bytes) : false;
                if (!$image || $image[2] !== IMAGETYPE_PNG || $image[0] < 1 || $image[1] < 1
                    || $image[0] > 1024 || $image[1] > 1024) {
                    throw ValidationException::withMessages(['logo_data' => 'El logo debe ser PNG válido de hasta 128 KB y 1024 × 1024 píxeles.']);
                }
            }
            DB::table('planilla_settings')->where('organization_id', $p['organization_id'])->update([
                'version' => $old['version'] + 1,
                'allowed_columns' => json_encode($data['allowed_columns'], JSON_THROW_ON_ERROR),
                'h1' => $data['h1'], 'h2' => $data['h2'], 'h3' => $data['h3'],
                'h1_en' => $data['h1_en'], 'h2_en' => $data['h2_en'], 'h3_en' => $data['h3_en'],
                'logo_data' => array_key_exists('logo_data', $data) ? $data['logo_data'] : $old['logo_data'],
                'delegated_roles' => json_encode($data['delegated_roles'] ?? $old['delegated_roles'], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            Outbox::record('planilla_settings.updated', $p['organization_id'], $p['user_id'], $p['organization_id']);
            return ['data' => $this->read($p)];
        });
    }
}
