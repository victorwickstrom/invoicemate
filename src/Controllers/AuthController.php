<?php
namespace Invoicemate\Controllers;

use Invoicemate\Utils\JWT;
use PDO;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class AuthController
{
    private PDO $pdo;
    private JWT $jwt;

    public function __construct(PDO $pdo, JWT $jwt)
    {
        $this->pdo = $pdo;
        $this->jwt = $jwt;
    }

    /**
     * Handle login and return a JWT if credentials are valid.
     */
    public function login(Request $request, Response $response): Response
    {
        $data = json_decode((string)$request->getBody(), true);
        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;
        if (!$email || !$password) {
            return $this->json($response, ['error' => 'Missing credentials'], 400);
        }
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = :email');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return $this->json($response, ['error' => 'Invalid credentials'], 401);
        }
        $now = time();
        $claims = [
            'sub' => $user['id'],
            'organization_id' => $user['organization_id'],
            'roles' => $user['roles'],
            'iat' => $now,
            'exp' => $now + 3600 * 24 * 7, // 7 days
        ];
        $token = $this->jwt->encode($claims);
        return $this->json($response, ['token' => $token]);
    }

    /**
     * Return current user claims.
     */
    public function me(Request $request, Response $response): Response
    {
        $claims = $request->getAttribute('user');
        return $this->json($response, ['user' => $claims]);
    }

    private function json(Response $response, $data, int $status = 200): Response
    {
        $response->getBody()->rewind();
        $response->getBody()->write(json_encode($data));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}