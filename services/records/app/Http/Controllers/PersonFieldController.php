<?php
namespace App\Http\Controllers;
use App\Application\PersonFields;
use Illuminate\Http\Request;
use Srd\Access;
final class PersonFieldController
{
    public function index(PersonFields $fields): array
    {
        $p = Access::require('persons.read');
        return ['data' => $fields->permissions($p, $fields->schema($p['organization_id']))];
    }
    public function update(Request $r, PersonFields $fields): array
    {
        $p = Access::require('persons.read');
        abort_unless($fields->permissions($p, $fields->schema($p['organization_id']))['can_manage'], 403);
        $d = $r->validate([
            'delegated_roles' => 'sometimes|array|list|max:4', 'delegated_roles.*' => 'required|string|distinct:strict|in:registrar,treasurer,auditor,viewer',
            'version' => 'required|integer|min:0', 'fields' => 'present|array|list|max:20',
            'fields.*' => 'required|array:id,label,label_en,type,active,required,options',
            'fields.*.id' => 'required|uuid|distinct:strict', 'fields.*.label' => 'required|string|max:80',
            'fields.*.label_en' => 'required|string|max:80',
            'fields.*.type' => 'required|in:text,date,number,select',
            'fields.*.active' => 'required|boolean', 'fields.*.required' => 'required|boolean',
            'fields.*.options' => 'present|array|list|max:50',
            'fields.*.options.*' => 'required|array:id,label,label_en,active',
            'fields.*.options.*.id' => 'required|uuid',
            'fields.*.options.*.label' => 'required|string|max:80',
            'fields.*.options.*.label_en' => 'required|string|max:80',
            'fields.*.options.*.active' => 'required|boolean',
        ]);
        $d['version'] = (int) $d['version'];
        foreach ($d['fields'] as &$field) {
            $field['active'] = (bool) $field['active'];
            $field['required'] = (bool) $field['required'];
            foreach ($field['options'] as &$option) $option['active'] = (bool) $option['active'];
            unset($option);
        }
        unset($field);
        return $fields->configure($p, $d);
    }
}
