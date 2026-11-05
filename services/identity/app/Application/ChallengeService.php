<?php

namespace App\Application;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Srd\DependencyFailure;
use Srd\Outbox;

final class ChallengeService
{
    public function digest(string $id, string $purpose, string $destination, string $secret): string
    {
        $key = config('srd.challenge_key');
        if (! is_string($key) || strlen($key) < 32) {
            throw new DependencyFailure;
        }

        return hash_hmac('sha256', $id.'|'.$purpose.'|'.$destination.'|'.$secret, $key);
    }

    // Caller holds a lock on users: sends and hourly limits are serialized per account.
    public function issue(object $user, string $org, string $purpose, string $destination, ?string $terms = null): array
    {
        $previous = DB::table('auth_challenges')->where('user_id', $user->id)->where('purpose', $purpose);
        $last = (clone $previous)->latest('created_at')->first();
        abort_if($last && Carbon::parse($last->created_at)->gt(now()->subSeconds(60)), 429);
        abort_if((clone $previous)->where('created_at', '>', now()->subHour())->count() >= 5, 429);
        $id = (string) Str::uuid();
        $secret = $purpose === 'reset' ? bin2hex(random_bytes(32)) : str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = now()->addMinutes($purpose === 'reset' ? 15 : 5);
        (clone $previous)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        DB::table('auth_challenges')->insert(['id' => $id, 'user_id' => $user->id, 'organization_id' => $org, 'purpose' => $purpose, 'destination' => $destination, 'digest' => $this->digest($id, $purpose, $destination, $secret), 'terms_version_id' => $terms, 'expires_at' => $expires, 'created_at' => now(), 'sent_at' => null]);
        $link = $purpose === 'reset' ? config('srd.public_url').'/reset#'.$id.'.'.$secret : null;
        $language = ($user->language ?? 'es') === 'en' ? 'en' : 'es';
        try {
            Mail::send('security-mail', ['code' => $secret, 'purpose' => $purpose, 'link' => $link, 'language' => $language], function ($m) use ($destination, $purpose, $language) {
                $m->to($destination)->subject($language === 'en'
                    ? ($purpose === 'reset' ? 'Reset your SRD access' : 'Your SRD security code')
                    : ($purpose === 'reset' ? 'Restablece tu acceso a SRD' : 'Tu código de seguridad de SRD'));
            });
        } catch (\Throwable) {
            // Commit the failed attempt and invalidation; never tell the caller that mail was sent.
            DB::table('auth_challenges')->where('id', $id)->update(['consumed_at' => now()]);
            Outbox::record('auth.delivery_failed', $org, $user->id, $id, 'failed');

            return ['delivery_failed' => true];
        }
        DB::table('auth_challenges')->where('id', $id)->update(['sent_at' => now()]);
        Outbox::record('auth.challenge_sent', $org, $user->id, $id);

        return ['challenge_id' => $id, 'expires_at' => $expires->toISOString(), 'resend_after' => 60];
    }

    // Return an error instead of throwing so invalid-attempt increments commit.
    public function consume(string $id, string $purpose, string $secret, ?string $userId = null): ?object
    {
        $c = DB::table('auth_challenges')->where('id', $id)->lockForUpdate()->first();
        if (! $c || $c->purpose !== $purpose || ($userId !== null && $c->user_id !== $userId) || $c->consumed_at || ! $c->sent_at || $c->attempts >= 5 || Carbon::parse($c->expires_at)->lte(now())) {
            return null;
        }
        DB::table('auth_challenges')->where('id', $id)->increment('attempts');
        if (! hash_equals($c->digest, $this->digest($c->id, $purpose, $c->destination, $secret))) {
            Outbox::record('auth.code_rejected', $c->organization_id, $c->user_id, $c->id, 'rejected');

            return null;
        }
        DB::table('auth_challenges')->where('id',$id)->update(['consumed_at' => now()]);

        return $c;
    }
}
