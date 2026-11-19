<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/** Personne morale : associations JaXaJ, clubs et associations moto… (lecture seule pour l'instant). */
#[ORM\Entity]
#[ORM\Table(name: 'organisation', schema: 'annuaire')]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('MEMBRES_LIRE')", order: ['nom' => 'ASC']),
        new Get(security: "is_granted('MEMBRES_LIRE')"),
    ],
    normalizationContext: ['groups' => ['organisation:lire']],
)]
class Organisation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[Groups(['organisation:lire'])]
    private Uuid $id;

    #[ORM\Column]
    #[Groups(['organisation:lire'])]
    private string $nom;

    #[ORM\Column(nullable: true)]
    #[Groups(['organisation:lire'])]
    private ?string $sigle = null;

    #[ORM\Column]
    #[Groups(['organisation:lire'])]
    private string $genre;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'parent_id')]
    #[Groups(['organisation:lire'])]
    private ?Organisation $parent = null;

    public function getId(): Uuid { return $this->id; }
    public function getNom(): string { return $this->nom; }
    public function getSigle(): ?string { return $this->sigle; }
    public function getGenre(): string { return $this->genre; }
    public function getParent(): ?Organisation { return $this->parent; }
}
