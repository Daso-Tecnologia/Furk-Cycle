<?php

declare(strict_types=1);

namespace FurkiCycle\Users;

use FurkiCycle\Config\Env;
use FurkiCycle\Database\MySqlConnection;
use PDO;
use RuntimeException;

final class UserRepository
{
    private const ALLOWED_ROLES = ['ADMIN', 'SUPERVISOR'];

    private readonly PDO $connection;

    public function __construct(Env $env)
    {
        $this->connection = MySqlConnection::create($env);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $statement = $this->connection->query(
            'SELECT id, name, email, role, is_active, last_login_at, created_at, updated_at
             FROM users
             ORDER BY name ASC',
        );

        return $statement->fetchAll();
    }

    /** @return ?array<string, mixed> */
    public function findById(int $id): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, name, email, role, is_active, created_at, updated_at
             FROM users
             WHERE id = :id
             LIMIT 1',
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return ?array<string, mixed> */
    public function findByEmail(string $email): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, email FROM users WHERE email = :email LIMIT 1',
        );
        $statement->execute(['email' => $email]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function create(string $name, string $email, string $role, string $password): int
    {
        $this->validateRole($role);
        $statement = $this->connection->prepare(
            'INSERT INTO users (name, email, password_hash, role, is_active, created_at, updated_at)
             VALUES (:name, :email, :password_hash, :role, 1, NOW(6), NOW(6))',
        );
        $statement->execute([
            'name' => $name,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $id, string $name, string $email, string $role, bool $isActive): void
    {
        $this->validateRole($role);
        $statement = $this->connection->prepare(
            'UPDATE users
             SET name = :name, email = :email, role = :role, is_active = :is_active, updated_at = NOW(6)
             WHERE id = :id',
        );
        $statement->execute([
            'id' => $id,
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'is_active' => $isActive ? 1 : 0,
        ]);
    }

    public function resetPassword(int $id, string $password): void
    {
        $statement = $this->connection->prepare(
            'UPDATE users SET password_hash = :hash, updated_at = NOW(6) WHERE id = :id',
        );
        $statement->execute([
            'id' => $id,
            'hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
    }

    public function assertEmailUnique(string $email, ?int $ignoreId = null): void
    {
        $existing = $this->findByEmail($email);
        if ($existing !== null && (int) $existing['id'] !== $ignoreId) {
            throw new RuntimeException('Já existe um usuário com este e-mail.');
        }
    }

    private function validateRole(string $role): void
    {
        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            throw new RuntimeException('Perfil inválido. Use ADMIN ou SUPERVISOR.');
        }
    }
}
