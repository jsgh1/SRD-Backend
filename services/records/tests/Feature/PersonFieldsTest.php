<?php
namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Srd\Access;
use Srd\Testing\SignedRequests;
use Tests\TestCase;

final class PersonFieldsTest extends TestCase
{
    use RefreshDatabase, SignedRequests;
    private array $p = ['organization_id' => '11111111-1111-4111-8111-111111111111', 'user_id' => '22222222-2222-4222-8222-222222222222', 'role' => 'admin'];
    private function field(string $type = 'text', array $changes = []): array {
        return array_replace(['id' => (string) Str::uuid(), 'label' => 'Campo sintético', 'label_en' => 'Synthetic field', 'type' => $type, 'active' => true, 'required' => false, 'options' => []], $changes);
    }
    private function person(array $changes = []): array {
        return array_replace(['document_type' => 'CC', 'document_number' => 'TEST-'.Str::random(8), 'first_names' => 'Persona ficticia', 'status' => 'pending', 'schema_version' => 1, 'authorization_basis' => 'Prueba sintética', 'authorization_purpose' => 'Verificación local', 'note' => 'Nota reservada'], $changes);
    }
    private function configure(array $fields, int $version = 0) {
        foreach ($fields as &$field) {
            foreach ($field['options'] ?? [] as $index => $option) $field['options'][$index]['label_en'] ??= 'Synthetic option';
        }
        unset($field);
        return $this->internal('PUT', 'person-fields', ['version' => $version, 'fields' => $fields], $this->p);
    }

    public function test_english_labels_are_required_on_write_and_legacy_schema_remains_readable(): void
    {
        $field = $this->field('select', ['options' => [['id' => (string) Str::uuid(), 'label' => 'Antigua', 'active' => true]]]);
        $withoutEnglish = $field; unset($withoutEnglish['label_en']);
        $this->internal('PUT', 'person-fields', ['version' => 0, 'fields' => [$withoutEnglish]], $this->p)->assertUnprocessable();
        $this->internal('PUT', 'person-fields', ['version' => 0, 'fields' => [$field]], $this->p)->assertUnprocessable();
        $this->assertDatabaseCount('person_field_schemas', 0);
        DB::table('person_field_schemas')->insert(['organization_id' => $this->p['organization_id'], 'version' => 1, 'fields' => json_encode([$withoutEnglish], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        $this->internal('GET', 'person-fields', [], $this->p)->assertOk()->assertJsonPath('data.fields.0.label', $field['label'])->assertJsonMissingPath('data.fields.0.label_en');
    }

    public function test_date_ranges_are_inclusive_scoped_typed_and_combinable(): void
    {
        $date = $this->field('date'); $text = $this->field();
        $this->configure([$date,$text])->assertOk();
        foreach (['2024-02-28','2024-02-29','2024-03-01','2024-03-02',null] as $value) {
            $values = [$text['id']=>'Norte']; if ($value !== null) $values[$date['id']]=$value;
            $this->internal('POST','persons',$this->person(['custom_values'=>$values]),$this->p)->assertOk();
        }
        $range=['field_id'=>$date['id'],'value'=>'2024-02-29','operator'=>'between','value_to'=>'2024-03-01'];
        $viewer=array_replace($this->p,['role'=>'viewer']);
        $this->internal('GET','persons',['custom_filters'=>[$range]],$viewer)->assertOk()->assertJsonPath('data.total',2)->assertJsonMissingPath('data.items.0.note');
        $this->internal('GET','persons',['custom_filters'=>[array_replace($range,['value_to'=>'2024-02-29'])]],$viewer)->assertOk()->assertJsonPath('data.total',1);
        $this->internal('GET','persons',['custom_filters'=>[$range,['field_id'=>$text['id'],'value'=>'Sur']]],$viewer)->assertOk()->assertJsonPath('data.total',0);
        $this->internal('GET','persons',['custom_filters'=>[['field_id'=>$date['id'],'value'=>'2024-02-29']]],$viewer)->assertOk()->assertJsonPath('data.total',1);
        $this->internal('GET','persons',['custom_filters'=>[$range]],array_replace($viewer,['organization_id'=>(string)Str::uuid()]))->assertUnprocessable();
        foreach ([['value_to'=>'2024-02-28'],['value_to'=>'2023-02-29'],['value'=>'2024-02-30'],['value_to'=>null],['value_to'=>['2024-03-01']],['operator'=>'gte'],['operator'=>'eq'],['field_id'=>$text['id']]] as $patch) {
            $this->internal('GET','persons',['custom_filters'=>[array_replace($range,$patch)]],$viewer)->assertUnprocessable();
        }
        $missing=$range; unset($missing['value_to']);
        $this->internal('GET','persons',['custom_filters'=>[$missing]],$viewer)->assertUnprocessable();
        $date['active']=false; $this->configure([$date,$text],1)->assertOk();
        $this->internal('GET','persons',['custom_filters'=>[$range]],$viewer)->assertOk()->assertJsonPath('data.total',2);
    }

    public function test_person_search_treats_wildcards_literally_and_never_searches_notes(): void
    {
        $person = $this->person(['schema_version' => 0, 'first_names' => "Equipo 50%_! O'Reilly", 'last_names' => 'Apellido sintético', 'note' => 'CLAVE-NOTA-RESERVADA']);
        $id = $this->internal('POST', 'persons', $person, $this->p)->assertOk()->json('data.id');
        $this->internal('POST', 'persons', $this->person(['schema_version' => 0]), $this->p)->assertOk();
        foreach (['50%_!', "O'Reilly", 'Apellido', $person['document_number']] as $query) {
            $this->internal('GET', 'persons', ['q' => $query], $this->p)->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.id', $id)->assertJsonMissingPath('data.items.0.note');
        }
        $this->internal('GET', 'persons', ['q' => 'CLAVE-NOTA-RESERVADA'], $this->p)->assertOk()->assertJsonPath('data.total', 0);
        $this->internal('GET', 'persons', ['q' => '50%_!'], array_replace($this->p, ['organization_id' => (string) Str::uuid()]))->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_filters_combine_values_affiliation_pagination_and_scope(): void
    {
        $text = $this->field(); $number = $this->field('number');
        $this->configure([$text, $number])->assertOk();
        for ($i = 0; $i < 12; $i++) {
            $this->internal('POST', 'persons', $this->person(['zone' => 'urban', 'affiliated' => false, 'custom_values' => [$text['id'] => "50%_ O'Reilly", $number['id'] => '0']]), $this->p)->assertOk();
        }
        $filters = ['zone' => 'urban', 'affiliated' => '0', 'custom_filters' => [['field_id' => $text['id'], 'value' => "50%_ O'Reilly"], ['field_id' => $number['id'], 'value' => '0']]];
        $first = $this->internal('GET', 'persons', $filters, $this->p)->assertOk()->assertJsonPath('data.total', 12)->assertJsonCount(10, 'data.items')->assertJsonMissingPath('data.items.0.note')->json('data.items');
        $second = $this->internal('GET', 'persons', $filters + ['page' => 2], $this->p)->assertOk()->assertJsonCount(2, 'data.items')->json('data.items');
        $this->assertEmpty(array_intersect(array_column($first, 'id'), array_column($second, 'id')));
        $filters['custom_filters'][1]['value'] = '0.0';
        $this->internal('GET', 'persons', $filters, $this->p)->assertOk()->assertJsonPath('data.total', 0);
        $other = array_replace($this->p, ['organization_id' => (string) Str::uuid()]);
        $this->internal('GET', 'persons', $filters, $other)->assertUnprocessable();
        $this->internal('GET', 'persons', [], $other)->assertOk()->assertJsonPath('data.total', 0);
        foreach ([array_fill(0, 4, $filters['custom_filters'][0]), [$filters['custom_filters'][0], $filters['custom_filters'][0]], [['field_id' => 'bad->path', 'value' => 'x']], [['field_id' => $number['id'], 'value' => '1e5']], [['field_id' => $text['id'], 'value' => str_repeat('a', 121)]]] as $invalid) {
            $this->internal('GET', 'persons', ['custom_filters' => $invalid], $this->p)->assertUnprocessable();
        }
    }

    public function test_schema_is_scoped_read_only_by_default_and_administration_is_restricted(): void
    {
        $field = $this->field();
        foreach (Access::ROLES as $role) {
            $p = array_replace($this->p, ['role' => $role]);
            $this->internal('GET', 'person-fields', [], $p)->assertOk()->assertJsonPath('data.version', 0)->assertJsonPath('data.fields', []);
            if (!in_array($role, ['admin', 'superadmin'])) $this->internal('PUT', 'person-fields', ['version' => 0, 'fields' => [$field]], $p)->assertForbidden();
        }
        $this->assertDatabaseCount('person_field_schemas', 0);
        $this->configure([$field])->assertOk()->assertJsonPath('data.version', 1);
        $other = array_replace($this->p, ['organization_id' => (string) Str::uuid()]);
        $this->internal('GET', 'person-fields', ['organization_id' => $this->p['organization_id']], $other)->assertOk()->assertJsonPath('data.fields', []);
        $this->assertDatabaseCount('person_field_schemas', 1);
        $this->assertDatabaseHas('outbox_events', ['action' => 'person_fields.updated', 'organization_id' => $this->p['organization_id']]);
    }

    public function test_schema_enforces_versions_immutable_types_and_non_destructive_options(): void
    {
        $option = ['id' => (string) Str::uuid(), 'label' => 'Opción original', 'active' => true];
        $select = $this->field('select', ['options' => [$option]]);
        $text = $this->field();
        $this->configure([$select, $text])->assertOk();
        $this->configure([$select, $text])->assertConflict();
        $this->configure([$select], 1)->assertUnprocessable();
        $this->configure([array_replace($select, ['type' => 'text']), $text], 1)->assertUnprocessable();
        $this->configure([array_replace($select, ['options' => [], 'active' => false]), $text], 1)->assertUnprocessable();
        $this->configure([array_replace($select, ['options' => [array_replace($option, ['active' => false])]]), $text], 1)->assertUnprocessable();
        $this->configure([$text, array_replace($select, ['active' => false])], 1)->assertOk()->assertJsonPath('data.fields.0.id', $text['id']);
        $this->internal('GET', 'person-fields', [], $this->p)->assertJsonPath('data.version', 2);
    }

    public function test_typed_values_validate_and_complete_requires_active_mandatory_fields(): void
    {
        $date = $this->field('date', ['required' => true]); $number = $this->field('number'); $text = $this->field();
        $this->configure([$date, $number, $text])->assertOk();
        $complete = $this->person(['status' => 'complete', 'last_names' => 'Prueba', 'affiliated' => true, 'zone' => 'urban', 'gender' => 'other', 'birth_date' => '1990-01-01', 'phone' => '3000000000', 'email' => 'persona@example.test', 'position_code' => 'other', 'descriptive_role' => 'viewer', 'address' => 'Calle ficticia', 'neighborhood' => 'Barrio de prueba']);
        $this->internal('POST', 'persons', $complete, $this->p)->assertUnprocessable();
        foreach (['2026-02-30', '10/09/2026'] as $invalid) $this->internal('POST', 'persons', $this->person(['custom_values' => [$date['id'] => $invalid]]), $this->p)->assertUnprocessable();
        foreach (['1e5', '1.12345', '1000000000000', []] as $invalid) $this->internal('POST', 'persons', $this->person(['custom_values' => [$number['id'] => $invalid]]), $this->p)->assertUnprocessable();
        $this->internal('POST', 'persons', $this->person(['custom_values' => [$text['id'] => str_repeat('x', 501)]]), $this->p)->assertUnprocessable();
        $this->assertDatabaseCount('persons', 0);
        $this->internal('POST', 'persons', $this->person(), $this->p)->assertOk();
        $values = [$date['id'] => '2026-09-10', $number['id'] => '0', $text['id'] => 'Texto de prueba'];
        $id = $this->internal('POST', 'persons', $complete + ['custom_values' => $values], $this->p)->assertOk()->json('data.id');
        $this->internal('GET', 'persons/'.$id, [], $this->p)->assertJsonPath('data.custom_fields.'.$number['id'].'.value', '0');
        $this->internal('PATCH', 'persons/'.$id, array_replace($complete, ['version' => 1, 'custom_values' => [$date['id'] => null]]), $this->p)->assertUnprocessable();
        $this->assertDatabaseHas('persons', ['id' => $id, 'version' => 1]);
    }

    public function test_inactive_option_retains_historical_label_but_cannot_be_selected_again(): void
    {
        $old = ['id' => (string) Str::uuid(), 'label' => 'Etiqueta histórica', 'label_en' => 'Historical option', 'active' => true];
        $new = ['id' => (string) Str::uuid(), 'label' => 'Opción nueva', 'label_en' => 'New option', 'active' => true];
        $field = $this->field('select', ['label_en' => 'Test field', 'options' => [$old, $new]]);
        $this->configure([$field])->assertOk();
        $person = $this->person(['custom_values' => [$field['id'] => $old['id']]]);
        $id = $this->internal('POST', 'persons', $person, $this->p)->assertOk()->json('data.id');
        $this->internal('GET', 'persons/'.$id, [], $this->p)->assertJsonPath('data.custom_fields.'.$field['id'].'.label_en', 'Test field')->assertJsonPath('data.custom_fields.'.$field['id'].'.display_en', 'Historical option');
        $changed = array_replace($field, ['label' => 'Nombre actualizado', 'options' => [array_replace($old, ['label' => 'Renombrada', 'active' => false]), $new]]);
        $this->configure([$changed], 1)->assertOk();
        $this->internal('PATCH', 'persons/'.$id, $person + ['version' => 1], $this->p)->assertConflict();
        $this->internal('PATCH', 'persons/'.$id, array_replace($person, ['version' => 1, 'schema_version' => 2]), $this->p)->assertOk();
        $viewer = array_replace($this->p, ['role' => 'viewer']);
        $this->internal('GET', 'persons/'.$id, [], $viewer)->assertOk()->assertJsonMissingPath('data.note')->assertJsonPath('data.custom_fields.'.$field['id'].'.display', 'Etiqueta histórica')->assertJsonPath('data.custom_fields.'.$field['id'].'.label', $field['label']);
        $this->internal('POST', 'persons', $this->person(['schema_version' => 2, 'custom_values' => [$field['id'] => $old['id']]]), $this->p)->assertUnprocessable();
        $this->internal('PATCH', 'persons/'.$id, array_replace($person, ['version' => 2, 'schema_version' => 2, 'custom_values' => [$field['id'] => $new['id']]]), $this->p)->assertOk();
        $this->internal('PATCH', 'persons/'.$id, array_replace($person, ['version' => 3, 'schema_version' => 2]), $this->p)->assertUnprocessable();
        $this->configure([array_replace($changed, ['active' => false])], 2)->assertOk();
        $this->internal('PATCH', 'persons/'.$id, array_replace($person, ['version' => 3, 'schema_version' => 3, 'custom_values' => [$field['id'] => null]]), $this->p)->assertUnprocessable();
        $this->internal('DELETE', 'persons/'.$id, ['version' => 3, 'confirmed' => true], $this->p)->assertOk();
        $this->assertDatabaseCount('person_field_values', 0);
        $this->assertStringNotContainsString('Etiqueta histórica', json_encode(DB::table('outbox_events')->get()));
    }

    public function test_unknown_fields_and_stale_forms_cannot_bypass_the_schema(): void
    {
        $field = $this->field(); $this->configure([$field])->assertOk();
        foreach ([['schema_version' => 0], ['custom_values' => ['note' => 'x']], ['custom_values' => [(string) Str::uuid() => 'x']]] as $change) {
            $response = $this->internal('POST', 'persons', $this->person($change), $this->p);
            if (isset($change['schema_version'])) $response->assertConflict(); else $response->assertUnprocessable();
        }
        $other = array_replace($this->p, ['organization_id' => (string) Str::uuid()]);
        $this->internal('POST', 'persons', $this->person(['schema_version' => 0, 'custom_values' => [$field['id'] => 'x']]), $other)->assertUnprocessable();
        $this->assertDatabaseCount('persons', 0);
        $this->assertDatabaseCount('person_field_values', 0);
    }

    public function test_schema_rejects_invalid_shapes_duplicate_ids_and_limits(): void
    {
        $field = $this->field();
        foreach ([[$field, $field], [array_replace($field, ['id' => 'first_names'])], [array_replace($field, ['type' => 'script'])], [array_replace($field, ['extra' => true])], [array_replace($field, ['options' => [['id' => (string) Str::uuid(), 'label' => 'x', 'active' => true]]])], array_map(fn () => $this->field(), range(1, 21))] as $fields) $this->configure($fields)->assertUnprocessable();
        $this->assertDatabaseCount('person_field_schemas', 0);
    }
}
