<?php
namespace App\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Srd\Access;
use Srd\Outbox;

final class PersonFilterSettings
{
    public const BASE = ['status','zone','affiliated','document_type','gender','descriptive_role','position_code','birth_date','registered_at'];
    public function read(array $p, bool $lock = false): array
    {
        if ($lock) DB::table('person_filter_settings')->upsert([
            ['organization_id'=>$p['organization_id'],'version'=>0,'base'=>json_encode(self::BASE),'custom'=>null,'delegated_roles'=>'[]','created_at'=>now(),'updated_at'=>now()],
        ], ['organization_id'], ['organization_id']);
        $query=DB::table('person_filter_settings')->where('organization_id',$p['organization_id']);
        if ($lock) $query->lockForUpdate();
        $row=$query->first();
        $value=$row ? ['version'=>(int)$row->version,'base'=>json_decode($row->base,true),'custom'=>$row->custom===null ? null : json_decode($row->custom,true),'delegated_roles'=>json_decode($row->delegated_roles,true)]
            : ['version'=>0,'base'=>self::BASE,'custom'=>null,'delegated_roles'=>[]];
        $value['can_delegate']=Access::allows($p['role'],'organization.manage');
        $value['can_manage']=$value['can_delegate'] || in_array($p['role'],$value['delegated_roles'],true);
        return $value;
    }
    public function update(array $p, array $data): array
    {
        return DB::transaction(function () use ($p,$data) {
            // Lock in the same order as field configuration and person writes.
            $schema=app(PersonFields::class)->schema($p['organization_id'],true);
            $old=$this->read($p,true);
            abort_unless($old['can_manage'],403);
            abort_if(array_key_exists('delegated_roles',$data) && !$old['can_delegate'],403);
            abort_unless($old['version']===(int)$data['version'],409,'Los filtros o sus permisos cambiaron. Recarga antes de guardar.');
            foreach ($data['custom'] as $id) {
                if (!in_array($id,array_column($schema['fields'],'id'),true)) throw ValidationException::withMessages(['custom'=>'Selecciona campos de esta junta.']);
            }
            DB::table('person_filter_settings')->where('organization_id',$p['organization_id'])->update([
                'version'=>$old['version']+1,'base'=>json_encode($data['base'],JSON_THROW_ON_ERROR),
                'custom'=>json_encode($data['custom'],JSON_THROW_ON_ERROR),
                'delegated_roles'=>json_encode($data['delegated_roles'] ?? $old['delegated_roles'],JSON_THROW_ON_ERROR),'updated_at'=>now(),
            ]);
            Outbox::record('person_filters.updated',$p['organization_id'],$p['user_id'],$p['organization_id']);
            return ['data'=>$this->read($p)];
        });
    }
}
