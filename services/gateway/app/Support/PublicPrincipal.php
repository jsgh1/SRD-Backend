<?php

namespace App\Support;

/**
 * Maps Identity's private session principal to the fields allowed in a browser response.
 * Keep this allowlist separate from the internal principal used for authorization and signing.
 */
final class PublicPrincipal
{
    private const USER_FIELDS = [
        'name',
        'email',
        'theme',
        'presence',
        'language',
        'title',
        'title_en',
        'bio',
        'bio_en',
    ];

    private const ORGANIZATION_FIELDS = [
        'id',
        'code',
        'name',
        'accent',
        'version',
    ];

    private const TERMS_FIELDS = [
        'id',
        'version',
        'body',
        'body_en',
    ];

    public static function from(array $principal): array
    {
        $user = is_array($principal['user'] ?? null) ? $principal['user'] : [];
        $organization = is_array($principal['organization'] ?? null) ? $principal['organization'] : [];
        $terms = is_array($organization['terms'] ?? null) ? $organization['terms'] : [];

        return [
            'user_id' => $principal['user_id'],
            'person_id' => $principal['person_id'] ?? null,
            'role' => $principal['role'],
            'user' => self::only($user, self::USER_FIELDS),
            'organization' => self::only($organization, self::ORGANIZATION_FIELDS) + [
                'terms' => self::only($terms, self::TERMS_FIELDS),
            ],
            'terms_required' => $principal['terms_required'],
        ];
    }

    private static function only(array $data, array $fields): array
    {
        return array_intersect_key($data, array_flip($fields));
    }
}
