<?php

namespace App\Libraries;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtService
{
    public static function encode(array $payload): string
    {
        return JWT::encode(
            $payload,
            getenv('JWT_SECRET'),
            'HS256'
        );
    }

    public static function decode(string $token): object
    {
        return JWT::decode(
            $token,
            new Key(getenv('JWT_SECRET'), 'HS256')
        );
    }

    public static function ttlSeconds(): int
    {
        return (int) (getenv('JWT_TTL_SECONDS') ?: 86400);
    }
}
