<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/** Catégorie de membre : full_patch, observateur, suiveur, support, public (adhérent événement)… */
#[ORM\Entity]
#[ORM\Table(name: 'categorie', schema: 'adhesion')]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('MEMBRES_LIRE')", order: ['ordre' => 'ASC']),
        new Get(security: "is_granted('MEMBRES_LIRE')"),
    ],
    normalizationContext: ['groups' => ['categorie:lire']],
)]
class Categorie
{
    #[ORM\Id]
    #[ORM\Column]
    #[ApiProperty(identifier: true)]
    #[Groups(['categorie:lire'])]
    private string $code;

    #[ORM\Column]
    #[Groups(['categorie:lire'])]
    private string $libelle;

    #[ORM\Column(name: 'membre_club')]
    #[Groups(['categorie:lire'])]
    private bool $membreClub;

    #[ORM\Column]
    private int $ordre;

    public function getCode(): string { return $this->code; }
    public function getLibelle(): string { return $this->libelle; }
    public function isMembreClub(): bool { return $this->membreClub; }
}
