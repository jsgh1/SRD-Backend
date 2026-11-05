<?php
namespace App\Http\Controllers;
use App\Application\PersonPositions;
use Illuminate\Http\Request;
use Srd\Access;
final class PersonPositionController {
    public function index(PersonPositions $positions): array {
        return ['data'=>$positions->catalog(Access::require('persons.read')['organization_id'])];
    }
    public function update(Request $r, PersonPositions $positions): array {
        $p = Access::require('organization.manage');
        $d = $r->validate(['version'=>'required|integer|min:0','items'=>'required|array|list|max:50',
            'items.*'=>'required|array:code,label,label_en,active','items.*.code'=>'required|string|max:60|regex:/^[a-z0-9-]+$/D|distinct:strict',
            'items.*.label'=>'required|string|max:80','items.*.label_en'=>'required|string|max:80','items.*.active'=>'required|boolean']);
        foreach ($d['items'] as &$item) $item['active'] = (bool)$item['active'];
        unset($item);
        return $positions->configure($p, $d);
    }
}
