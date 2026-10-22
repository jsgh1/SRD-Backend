<?php
namespace App\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Srd\Outbox;
use Srd\Access;

final class PersonFields
{
    public function schema(string $org, bool $lock = false): array
    {
        // An update-on-conflict takes a write lock directly; do not upgrade duplicate-insert shared locks.
        if ($lock) DB::table('person_field_schemas')->upsert([['organization_id' => $org, 'version' => 0, 'fields' => '[]', 'created_at' => now(), 'updated_at' => now()]], ['organization_id'], ['organization_id']);
        $query = DB::table('person_field_schemas')->where('organization_id', $org);
        if ($lock) $query->lockForUpdate();
        $row = $query->first();
        return $row ? ['version' => (int) $row->version, 'fields' => json_decode($row->fields, true, 512, JSON_THROW_ON_ERROR), 'delegated_roles' => json_decode($row->delegated_roles ?? '[]', true, 512, JSON_THROW_ON_ERROR)] : ['version' => 0, 'fields' => [], 'delegated_roles' => []];
    }
    public function permissions(array $p, array $schema): array {
        $schema['can_delegate'] = Access::allows($p['role'], 'organization.manage');
        $schema['can_manage'] = $schema['can_delegate'] || in_array($p['role'], $schema['delegated_roles'], true);
        return $schema;
    }
    private function fail(string $key, string $message): never { throw ValidationException::withMessages([$key => $message]); }
    public function configure(array $p, array $d): array
    {
        return DB::transaction(function () use ($p, $d) {
            $old = $this->schema($p['organization_id'], true);
            $permissions = $this->permissions($p, $old);
            abort_unless($permissions['can_manage'], 403);
            abort_if(array_key_exists('delegated_roles', $d) && !$permissions['can_delegate'], 403);
            abort_unless($old['version'] === $d['version'], 409, 'La configuración cambió. Recarga antes de guardar.');
            $next = array_column($d['fields'], null, 'id');
            foreach ($old['fields'] as $field) {
                $replacement = $next[$field['id']] ?? null;
                if (!$replacement) $this->fail('fields', 'Los campos guardados se desactivan; no se eliminan.');
                if ($replacement['type'] !== $field['type']) $this->fail('fields', 'El tipo de un campo guardado no puede cambiar.');
                $options = array_column($replacement['options'], null, 'id');
                foreach ($field['options'] as $option) if (!isset($options[$option['id']])) $this->fail('fields', 'Las opciones guardadas se desactivan; no se eliminan.');
            }
            foreach ($d['fields'] as $field) {
                if ($field['type'] !== 'select' && $field['options']) $this->fail('fields', 'Solo los campos de selección admiten opciones.');
                if ($field['type'] === 'select' && $field['active'] && !array_filter($field['options'], fn ($o) => $o['active'])) $this->fail('fields', 'Una selección activa necesita al menos una opción activa.');
                if (count(array_unique(array_column($field['options'], 'id'))) !== count($field['options'])) $this->fail('fields', 'No se permiten identificadores de opción repetidos.');
            }
            $version = $old['version'] + 1;
            $roles = $d['delegated_roles'] ?? $old['delegated_roles'];
            DB::table('person_field_schemas')->where('organization_id', $p['organization_id'])->update(['fields' => json_encode($d['fields'], JSON_THROW_ON_ERROR), 'delegated_roles' => json_encode($roles, JSON_THROW_ON_ERROR), 'version' => $version, 'updated_at' => now()]);
            Outbox::record('person_fields.updated', $p['organization_id'], $p['user_id'], $p['organization_id']);
            return ['data' => $this->permissions($p, ['version' => $version, 'fields' => $d['fields'], 'delegated_roles' => $roles])];
        });
    }
    public function snapshots(string $org, string $person): array
    {
        $json = DB::table('person_field_values')->where('organization_id', $org)->where('person_id', $person)->value('snapshots');
        return $json ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : [];
    }
    // The caller holds the schema lock throughout the person transaction.
    public function validateValues(array $schema, array $incoming, array $previous, bool $complete): array
    {
        $fields = array_column($schema['fields'], null, 'id');
        $values = $previous;
        foreach ($incoming as $id => $value) {
            $key = 'custom_values.'.$id;
            if (!isset($fields[$id])) $this->fail($key, 'El campo no pertenece a la configuración de esta junta.');
            $field = $fields[$id];
            $old = $previous[$id]['value'] ?? null;
            if (!$field['active']) {
                if ($value !== $old) $this->fail($key, 'El campo está inactivo y conserva su valor histórico.');
                continue;
            }
            if ($value === null || $value === '') { unset($values[$id]); continue; }
            $rules = match ($field['type']) {
                'text' => ['string', 'max:500'], 'date' => ['string', 'date_format:Y-m-d'],
                'number' => ['string', 'regex:/^-?[0-9]{1,12}(\.[0-9]{1,4})?$/D'], 'select' => ['string', 'uuid'],
            };
            if (Validator::make(['value' => $value], ['value' => $rules])->fails()) $this->fail($key, $field['label'].': valor inválido para el tipo del campo.');
            $display = $value;
            if ($field['type'] === 'select') {
                $option = array_column($field['options'], null, 'id')[$value] ?? null;
                if (!$option || (!$option['active'] && $value !== $old)) $this->fail($key, $field['label'].': la opción no está disponible.');
                $display = $option['label'];
            }
            // Preserve captured labels when a value has not changed.
            if ($value === $old) continue;
            $values[$id] = ['value' => $value, 'display' => $display, 'label' => $field['label'], 'type' => $field['type']];
            if (isset($field['label_en'])) $values[$id]['label_en'] = $field['label_en'];
            if ($field['type'] === 'select' && isset($option['label_en'])) $values[$id]['display_en'] = $option['label_en'];
        }
        if ($complete) foreach ($fields as $id => $field) {
            if ($field['active'] && $field['required'] && !isset($values[$id])) $this->fail('custom_values.'.$id, $field['label'].': obligatorio para completar el registro.');
        }
        return $values;
    }
    public function persist(string $org, string $person, array $values): void
    {
        DB::table('person_field_values')->updateOrInsert(['person_id' => $person, 'organization_id' => $org], ['snapshots' => json_encode((object) $values, JSON_THROW_ON_ERROR)]);
    }

    public function filter($query, string $org, array $filters): void
    {
        if (!$filters) return;
        $fields = array_column($this->schema($org)['fields'], null, 'id');
        foreach ($filters as $index => $filter) {
            $id = $filter['field_id'];
            $field = $fields[$id] ?? null;
            if (!$field) $this->fail('custom_filters.'.$index, 'El campo no pertenece a la configuración de esta junta.');
            $rules = match ($field['type']) {
                'text' => ['string', 'max:500'], 'date' => ['string', 'date_format:Y-m-d'],
                'number' => ['string', 'regex:/^-?[0-9]{1,12}(\.[0-9]{1,4})?$/D'], 'select' => ['string', 'uuid'],
            };
            if (Validator::make(['value' => $filter['value']], ['value' => $rules])->fails()) $this->fail('custom_filters.'.$index, 'El valor del filtro no corresponde al tipo del campo.');
            if ($field['type'] === 'select' && !in_array($filter['value'], array_column($field['options'], 'id'), true)) $this->fail('custom_filters.'.$index, 'La opción no pertenece al campo.');
            $between = ($filter['operator'] ?? 'eq') === 'between';
            if ($between) {
                if ($field['type'] !== 'date') $this->fail('custom_filters.'.$index, 'Los intervalos solo están disponibles para campos de fecha.');
                if (Validator::make(['value'=>$filter['value_to'] ?? null], ['value'=>['required','string','date_format:Y-m-d']])->fails()) $this->fail('custom_filters.'.$index, 'Indica una fecha final válida.');
                if ($filter['value_to'] < $filter['value']) $this->fail('custom_filters.'.$index, 'La fecha final no puede ser anterior a la inicial.');
            } elseif (array_key_exists('value_to', $filter)) $this->fail('custom_filters.'.$index, 'La fecha final requiere un filtro por intervalo.');
            // Only schema-owned UUIDs become JSON paths. Values remain bound parameters.
            $query->whereExists(function ($sub) use ($org, $id, $filter, $between) {
                $sub->selectRaw('1')->from('person_field_values')
                    ->whereColumn('person_field_values.person_id', 'persons.id')
                    ->where('person_field_values.organization_id', $org);
                // Validated ISO dates sort chronologically; both endpoints are inclusive.
                if ($between) $sub->whereBetween('snapshots->'.$id.'->value', [$filter['value'], $filter['value_to']]);
                else $sub->where('snapshots->'.$id.'->value', $filter['value']);
            });
        }
    }
}
