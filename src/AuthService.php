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
     *
     * @since 1.0.0
     */
    public function __construct(?string $jwtSecret = null)
    {
        $envSecret = $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: null;
        $this->jwtSecret = $jwtSecret ?? ($envSecret ?: 'changeme');
    }

    /**
     * Hash a plain text password using bcrypt.
     *
     * This helper wraps PHP's {@see password_hash()} to generate a secure bcrypt hash of a
     * user‑provided password.  The resulting hash should be stored in the database and
     * compared against future login attempts using {@see verifyPassword()}.
     *
     * @param string $password The raw password provided by the user.
     *
     * @return string The bcrypt hashed password ready for storage.
     *
     * @since 1.0.0
     */
    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    /**
     * Verify a password against a bcrypt hash.
     *
     * Given a plain text password and a previously generated bcrypt hash (typically
     * retrieved from persistent storage), this method uses {@see password_verify()}
     * to determine whether the password corresponds to the hash.
     *
     * @param string $password The raw password supplied by the user attempting to authenticate.
     * @param string $hash The stored bcrypt hash retrieved from your user database.
     *
     * @return bool True when the password matches the stored hash, false otherwise.
     *
     * @since 1.0.0
     */
    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Generate a signed JWT for the given user.
     *
     * Creates a JSON Web Token containing the user's ID, organisation ID and role.
     * The token is issued at the current time and expires after 24 hours.  It is
     * signed using the secret configured via {@see __construct()}.
     *
     * @param int $userId Identifier of the user being authenticated.
     * @param int $organizationId Identifier of the organisation the user belongs to.
     * @param string $role Role of the user (e.g. `admin`, `user`).
     *
     * @return string A signed JWT valid for 24 hours which may be returned to the client.
     *
     * @throws \Exception If the JWT library encounters an error while encoding.
     *
     * @since 1.0.0
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
     * Decodes a JWT string and verifies its signature and expiry.  On success,
     * an associative array containing the `user_id`, `organization_id` and `role`
     * claims is returned.  If the token is malformed, the signature does not match or
     * the token has expired, `null` is returned.
     *
     * @param string $token A JWT passed by the client via the `Authorization` header.
     *
     * @return array|null Associative array of claims (`user_id`, `organization_id`, `role`) or
     *                    `null` if the token is invalid or expired.
     *
     * @throws \RuntimeException If the token cannot be decoded or verified.
     *
     * @since 1.0.0
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