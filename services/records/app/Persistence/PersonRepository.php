<?php

namespace App\Persistence;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PersonRepository
{
    public function forOrganization(string $organizationId): Builder
    {
        return DB::table('persons')->where('organization_id', $organizationId);
    }
}
