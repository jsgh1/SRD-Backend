<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Srd\Access;
use Srd\Outbox;

final class ProfileController
{
    public function update(Request $r): array
    {
        $p = Access::require('profile.write');
        $d = $r->validate(['name' => 'required|string|max:120', 'theme' => 'required|in:light,dark',
            'presence' => 'required|in:online,away,dnd,invisible', 'language' => 'sometimes|in:es,en',
            'title' => 'nullable|string|max:120|required_with:title_en',
            'title_en' => 'nullable|string|max:120|required_with:title',
            'bio' => 'nullable|string|max:500|required_with:bio_en',
            'bio_en' => 'nullable|string|max:500|required_with:bio',
            'role' => 'prohibited', 'email' => 'prohibited']);
        DB::transaction(function () use ($d, $p) {
            DB::table('users')->where('id', $p['user_id'])->update(array_diff_key($d, array_flip(['title', 'title_en', 'bio', 'bio_en'])) + ['updated_at' => now()]);
            if (array_key_exists('title', $d) || array_key_exists('title_en', $d)) {
                if (!empty($d['title']) && !empty($d['title_en'])) {
                    DB::table('member_profile_titles')->updateOrInsert(
                        ['organization_id' => $p['organization_id'], 'user_id' => $p['user_id']],
                        ['title' => $d['title'], 'title_en' => $d['title_en']]);
                } else {
                    DB::table('member_profile_titles')->where('organization_id', $p['organization_id'])
                        ->where('user_id', $p['user_id'])->delete();
                }
            }
            if (array_key_exists('bio', $d) || array_key_exists('bio_en', $d)) {
                if (!empty($d['bio']) && !empty($d['bio_en'])) {
                    DB::table('member_profile_bios')->updateOrInsert(
                        ['organization_id' => $p['organization_id'], 'user_id' => $p['user_id']],
                        ['bio' => $d['bio'], 'bio_en' => $d['bio_en']]);
                } else {
                    DB::table('member_profile_bios')->where('organization_id', $p['organization_id'])
                        ->where('user_id', $p['user_id'])->delete();
                }
            }
            Outbox::record('profile.updated', $p['organization_id'], $p['user_id'], $p['user_id']);
        });

        return ['data' => $d];
    }
}
