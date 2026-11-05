<?php

use App\Application\InvitationService;
use App\Application\ExpiredSessionCloser;
use App\Application\ChatSocketClosureQueue;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Srd\InternalClient;
use Srd\Outbox;

require __DIR__.'/../../../packages/php/console.php';
Artisan::command('srd:invitations', function () {
    app(InvitationService::class)->deliver();
});
Schedule::command('srd:invitations')->everyMinute()->withoutOverlapping();

Artisan::command('srd:security-notices', function () {
    $ids = DB::table('security_notices')->whereNull('sent_at')
        ->where('attempts', '<', 4)->where('next_attempt_at', '<=', now())->limit(50)->pluck('id');
    foreach ($ids as $id) {
        DB::transaction(function () use ($id) {
            $notice = DB::table('security_notices')->where('id', $id)->lockForUpdate()->first();
            if (! $notice || $notice->sent_at || $notice->attempts >= 4 || Carbon::parse($notice->next_attempt_at)->gt(now())) {
                return;
            }
            try {
                $destination = Crypt::decryptString($notice->destination_encrypted);
                $language = DB::table('users')->where('id', $notice->user_id)->value('language') === 'en' ? 'en' : 'es';
                Mail::send('email-changed', ['language' => $language], function ($mail) use ($destination, $language) {
                    $mail->to($destination)->subject($language === 'en'
                        ? 'SRD email change notice' : 'Aviso de cambio de correo en SRD');
                });
                DB::table('security_notices')->where('id', $id)->update(['sent_at' => now(), 'destination_encrypted' => null]);
                Outbox::record('profile.email_notice_sent', $notice->organization_id, $notice->user_id, $id);
            } catch (Throwable) {
                $attempt = $notice->attempts + 1;
                DB::table('security_notices')->where('id', $id)->update(['attempts' => $attempt, 'next_attempt_at' => now()->addMinutes([1, 5, 15, 15][$attempt - 1])]);
            }
        });
    }
});
Schedule::command('srd:security-notices')->everyMinute()->withoutOverlapping();
Artisan::command('srd:expire-chat-sessions', function () {
    $revoked = app(ChatSocketClosureQueue::class)->deliver();
    $expired = app(ExpiredSessionCloser::class)->run();
    $this->info("Cierres confirmados: vencidas {$expired}, revocadas {$revoked}.");
});
Schedule::command('srd:expire-chat-sessions')->everyMinute()->withoutOverlapping();
Artisan::command('srd:bootstrap {organization_code}', function () {
    if (DB::table('users')->where('superadmin', true)->exists()) {
        $this->error('Ya existe el superadministrador inicial.');

        return 1;
    }
    $org = app(InternalClient::class)->call('configuration', 'GET', 'organizations/code/'.$this->argument('organization_code'));
    $email = strtolower($this->ask('Correo de acceso'));
    $name = $this->ask('Nombre');
    $password = $this->secret('Contraseña (mínimo 12 caracteres)');
    $confirm = $this->secret('Repite la contraseña');
    if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12 || $password !== $confirm || ! $name) {
        $this->error('Datos inválidos o contraseñas diferentes.');

        return 1;
    }
    DB::transaction(function () use ($email, $name, $password, $org) {
        $id = (string) Str::uuid();
        DB::table('users')->insert(['id' => $id, 'email' => $email, 'name' => $name, 'password' => Hash::make($password), 'superadmin' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('memberships')->insert(['id' => (string) Str::uuid(), 'organization_id' => $org['id'], 'user_id' => $id, 'role' => 'superadmin']);
        Outbox::record('auth.bootstrap', $org['id'], $id, $id);
    });
    $this->info('Cuenta inicial creada. El siguiente acceso requiere código por correo.');
});
