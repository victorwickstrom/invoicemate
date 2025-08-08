<?php
declare(strict_types=1);

namespace App;

/**
 * AuthService is responsible for validating incoming JWT/Bearer tokens against
 * a central authentication provider (e.g. a Drupal instance).  It exposes a
 * single method, validateToken(), which returns user details on success or
 * null on failure.  The concrete implementation here is intentionally simple
 * and designed for extension: in a production environment you would perform a
 * HTTP request to your identity provider and verify the signature of the
 * token.  The method signature allows throwing exceptions if the remote
 * service is unreachable.
 */
class AuthService
{
    /**
     * Validate a bearer token and return user information when valid.
     *
     * In a real implementation this method would communicate with a central
     * Drupal instance (or another authentication service) to validate the
     * provided token.  For now the implementation accepts a single static
     * token "test" and treats all other tokens as invalid.
     *
     * @param string $token The bearer token extracted from the Authorization header.
     *
     * @return array|null An associative array of user information if the token
     *                    is valid, or null when invalid.
     */
    public function validateToken(string $token): ?array
    {
        // Basic example: accept a single hard‑coded token.  Replace this logic
        // with a call to your authentication API.
        if ($token === 'test') {
            return [
                'user_id'  => 1,
                'username' => 'testuser',
                'roles'    => ['admin'],
            ];
        }

        return null;
    }
}