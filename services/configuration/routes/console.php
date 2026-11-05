<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Srd\Outbox;

require __DIR__.'/../../../packages/php/console.php';
Artisan::command('srd:organization {code} {name} {--terms-file=} {--terms-file-en=}', function () {
    $code = $this->argument('code');
    if (! preg_match('/^[a-z0-9-]{3,40}$/', $code)) {
        $this->error('Código: 3 a 40 letras minúsculas, números o guiones.');

        return 1;
    }
    $file = $this->option('terms-file');
    if (! $file || ! is_file($file)) {
        $this->error('Debes proporcionar --terms-file con los términos de la junta.');

        return 1;
    }
    $body = file_get_contents($file);
    $englishFile = $this->option('terms-file-en');
    if (! $englishFile || ! is_file($englishFile)) {
        $this->error('Debes proporcionar --terms-file-en con los términos en inglés.');
        return 1;
    }
    $bodyEn = file_get_contents($englishFile);
    if (strlen($body) < 20 || strlen($body) > 50000 || strlen($bodyEn) < 20 || strlen($bodyEn) > 50000) {
        $this->error('Términos inválidos.');

        return 1;
    }
    $id = DB::transaction(function () use ($code, $body, $bodyEn) {
        $id = (string) Str::uuid();
        DB::table('organizations')->insert(['id' => $id, 'code' => $code, 'name' => $this->argument('name'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('terms_versions')->insert(['id' => (string) Str::uuid(), 'organization_id' => $id, 'version' => 1, 'body' => $body, 'body_en' => $bodyEn, 'published_at' => now()]);
        Outbox::record('organization.created', $id, null, $id);

        return $id;
    });
    $this->info('Junta creada: '.$id);
});
