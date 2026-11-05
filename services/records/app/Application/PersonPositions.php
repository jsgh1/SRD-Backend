<?php
namespace App\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Srd\Outbox;

final class PersonPositions
{
    private const DEFAULTS = [
        'president'=>['Presidente','President'], 'vicepresident'=>['Vicepresidente','Vice president'],
        'secretary'=>['Secretario','Secretary'], 'treasurer'=>['Tesorero','Treasurer'],
        'fiscal'=>['Fiscal','Controller'], 'other'=>['Otro','Other'],
    ];
    public function defaults(): array {
        $items = [];
        foreach (self::DEFAULTS as $code => [$label, $english]) $items[] = ['code'=>$code,'label'=>$label,'label_en'=>$english,'active'=>true];
        return $items;
    }
    public function catalog(string $org, bool $lock = false): array {
        if ($lock) DB::table('person_position_catalogs')->upsert([['organization_id'=>$org,'version'=>0,'items'=>json_encode($this->defaults()),'created_at'=>now(),'updated_at'=>now()]], ['organization_id'], ['organization_id']);
        $q = DB::table('person_position_catalogs')->where('organization_id', $org);
        if ($lock) $q->lockForUpdate();
        $row = $q->first();
        if (!$row) return ['version'=>0,'items'=>$this->defaults()];
        $items = json_decode($row->items, true, 512, JSON_THROW_ON_ERROR);
        foreach ($items as &$item) {
            $default = self::DEFAULTS[$item['code']] ?? null;
            if (!isset($item['label_en']) && $default && $item['label'] === $default[0]) $item['label_en'] = $default[1];
        }
        unset($item);
        return ['version'=>(int)$row->version,'items'=>$items];
    }
    public function configure(array $p, array $d): array {
        return DB::transaction(function () use ($p, $d) {
            $old = $this->catalog($p['organization_id'], true);
            abort_unless($old['version'] === (int)$d['version'], 409, 'Los cargos cambiaron. Recarga antes de guardar.');
            $codes = array_column($d['items'], 'code');
            foreach ($old['items'] as $item) if (!in_array($item['code'], $codes, true)) throw ValidationException::withMessages(['items'=>'Los cargos guardados se desactivan; no se eliminan ni cambian de identificador.']);
            if (!array_filter($d['items'], fn ($i) => $i['active'])) throw ValidationException::withMessages(['items'=>'Conserva al menos un cargo activo.']);
            $version = $old['version'] + 1;
            DB::table('person_position_catalogs')->where('organization_id', $p['organization_id'])->update(['version'=>$version,'items'=>json_encode($d['items'], JSON_THROW_ON_ERROR),'updated_at'=>now()]);
            Outbox::record('person_positions.updated', $p['organization_id'], $p['user_id'], $p['organization_id']);
            return ['data'=>['version'=>$version,'items'=>$d['items']]];
        });
    }
    public function labels(array $catalog, ?string $code, ?object $previous): array {
        if ($code === null) return ['position_label'=>null,'position_label_en'=>null];
        $item = array_column($catalog['items'], null, 'code')[$code] ?? null;
        if (!$item || (!$item['active'] && $code !== ($previous->position_code ?? null))) throw ValidationException::withMessages(['position_code'=>'El cargo no está disponible en esta junta.']);
        if ($code === ($previous->position_code ?? null)) {
            return ['position_label'=>$previous->position_label ?? $item['label'],
                'position_label_en'=>$previous->position_label_en ?? (($previous->position_label ?? null) === $item['label'] ? ($item['label_en'] ?? null) : null)];
        }
        return ['position_label'=>$item['label'],'position_label_en'=>$item['label_en'] ?? null];
    }
}
