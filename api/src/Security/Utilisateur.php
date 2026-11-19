<?php

namespace App\Security;

use Lexik\Bundle\JWTAuthenticationBundle\Security\User\JWTUserInterface;

/**
 * Utilisateur reconstruit à partir du JWT émis par l'appli comptes.
 * Droits : « application:role » ou « application:role:perimetre » (UUID d'association).
 */
final class Utilisateur implements JWTUserInterface
{
    private function __construct(
        private string $email,
        private ?string $compte,
        private ?string $personne,
        private array $droits,
    ) {}

    public static function createFromPayload($username, array $payload): self
    {
        return new self($username, $payload['compte'] ?? null, $payload['personne'] ?? null,
            array_values(array_filter($payload['droits'] ?? [], 'is_string')));
    }

    public function getUserIdentifier(): string { return $this->email; }
    public function getCompte(): ?string { return $this->compte; }
    public function getPersonne(): ?string { return $this->personne; }
    public function getDroits(): array { return $this->droits; }
    public function getRoles(): array { return ['ROLE_USER']; }
    public function eraseCredentials(): void {}

    /**
     * Le rôle donné dans l'application « membres » : null s'il ne l'a pas, sinon la liste des
     * associations concernées ([] = toutes).
     */
    public function perimetre(string $role): ?array
    {
        $associations = null;
        foreach ($this->droits as $droit) {
            $parties = explode(':', $droit, 3);
            if ('membres' !== $parties[0] || ($parties[1] ?? null) !== $role) {
                continue;
            }
            if (!isset($parties[2])) {
                return [];
            }
            $associations[] = $parties[2];
        }

        return $associations;
    }
}
