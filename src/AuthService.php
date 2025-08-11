<?php
declare(strict_types=1);

namespace App;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * AuthService encapsulates authentication related helpers such as password
 * hashing, verification and JSON Web Token (JWT) issuance and validation.  It
 * replaces the previous stub implementation that only accepted a static
 * bearer token.  Tokens are signed using the secret defined in the
 * `JWT_SECRET` environment variable and include the user id, organisation
 * id and role.  Tokens expire after 24 hours.
 */
class AuthService
{
    private string $jwtSecret;

    /**
     * Construct a new AuthService.
     *
     * @param string|null $jwtSecret Optional secret used for signing tokens.  If
     *                                omitted the value will be read from the
     *                                environment variable `JWT_SECRET`.  A
     *                                default fallback value of "changeme" is
     *                                used if no environment variable is set.
     */
    public function __construct(?string $jwtSecret = null)
    {
        $envSecret = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: null;
        $this->jwtSecret = $jwtSecret ?? ($envSecret ?: 'changeme');
    }

    /**
     * Hash a plain text password using bcrypt.
     *
     * @param string $password The raw password provided by the user.
     * @return string The bcrypt hashed password ready for storage.
     */
    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    /**
     * Verify a password against a bcrypt hash.
     *
     * @param string $password The raw password supplied by the user.
     * @param string $hash     The stored password hash.
     * @return bool True if the password matches, otherwise false.
     */
    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Generate a signed JWT for the given user.
     *
     * @param int    $userId          Identifier of the user being authenticated.
     * @param int    $organizationId  Identifier of the organisation the user belongs to.
     * @param string $role            Role of the user (e.g. admin, user).
     *
     * @return string A signed JWT valid for 24 hours.
     */
    public function generateToken(int $userId, int $organizationId, string $role): string
    {
        $now  = time();
        $exp  = $now + 24 * 60 * 60; // 24 hour expiry
        $payload = [
            'iat'             => $now,
            'exp'             => $exp,
            'user_id'         => $userId,
            'organization_id' => $organizationId,
            'role'            => $role,
        ];
        return JWT::encode($payload, $this->jwtSecret, 'HS256');
    }

    /**
     * Validate a JWT and return the contained claims.
     *
     * @param string $token A JWT passed by the client via the Authorization header.
     * @return array|null The decoded payload or null if the token is invalid or expired.
     */
    public function validateToken(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
            // convert the decoded object into an associative array
            return [
                'user_id'         => $decoded->user_id ?? null,
                'organization_id' => $decoded->organization_id ?? null,
                'role'            => $decoded->role ?? null,
            ];
        } catch (\Throwable $e) {
            // On any error (signature verification, expiry, decode failure) we
            // return null to indicate the token is invalid.
            return null;
        }
    }
}