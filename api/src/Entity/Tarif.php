<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Doctrine\Periode;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Tarif voté en AG : montant d'une catégorie, par mois ou par an, sur une période de validité
 * (début et fin inclus, fin vide = en vigueur). Association vide : toutes les associations ;
 * un tarif propre à une association l'emporte. Saisie réservée à l'administration.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tarif', schema: 'adhesion')]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('MEMBRES_LIRE')", paginationEnabled: false),
        new Get(security: "is_granted('MEMBRES_LIRE')"),
        new Post(security: "is_granted('MEMBRES_ADMIN')"),
        new Patch(security: "is_granted('MEMBRES_ADMIN')"),
        new Delete(security: "is_granted('MEMBRES_ADMIN')"),  // refusé s'il a servi à une cotisation
    ],
    normalizationContext: ['groups' => ['tarif:lire']],
    denormalizationContext: ['groups' => ['tarif:ecrire']],
    order: ['categorie' => 'ASC', 'validite' => 'DESC'],
)]
class Tarif
{
    public const PERIODICITES = ['mensuelle', 'annuelle', 'ponctuelle'];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[Groups(['tarif:lire'])]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'categorie', referencedColumnName: 'code', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['tarif:lire', 'tarif:ecrire'])]
    private ?Categorie $categorie = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'association_id')]
    #[Assert\Expression("value === null or value.getGenre() === 'association_jaxaj'", message: 'Un tarif s\'applique à une association JaXaJ, ou à toutes.')]
    #[Groups(['tarif:lire', 'tarif:ecrire'])]
    private ?Organisation $association = null;

    #[ORM\Column]
    #[Assert\Choice(choices: self::PERIODICITES, message: 'Périodicité : mensuelle, annuelle ou ponctuelle.')]
    #[Groups(['tarif:lire', 'tarif:ecrire'])]
    private string $periodicite = 'mensuelle';

    #[ORM\Column(type: 'decimal', precision: 8, scale: 2)]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    #[Groups(['tarif:lire', 'tarif:ecrire'])]
    private ?string $montant = null;

    #[ORM\Column(type: 'daterange')]
    private ?Periode $validite = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['tarif:lire', 'tarif:ecrire'])]
    private ?string $decision = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->validite = new Periode(new \DateTimeImmutable('first day of january this year'));
    }

    public function getId(): Uuid { return $this->id; }
    public function getCategorie(): ?Categorie { return $this->categorie; }
    public function setCategorie(Categorie $c): void { $this->categorie = $c; }
    public function getAssociation(): ?Organisation { return $this->association; }
    public function setAssociation(?Organisation $o): void { $this->association = $o; }
    public function getPeriodicite(): string { return $this->periodicite; }
    public function setPeriodicite(string $p): void { $this->periodicite = $p; }
    public function getMontant(): ?string { return $this->montant; }
    public function setMontant(?string $m): void { $this->montant = $m; }
    public function getDecision(): ?string { return $this->decision; }
    public function setDecision(?string $d): void { $this->decision = '' === trim((string) $d) ? null : trim($d); }

    #[Groups(['tarif:lire'])]
    public function getDebut(): ?\DateTimeImmutable { return $this->validite?->debut; }

    #[Groups(['tarif:ecrire'])]
    public function setDebut(\DateTimeImmutable $debut): void { $this->validite = new Periode($debut, $this->validite?->fin, false); }

    /** Dernier jour de validité, inclus ; vide tant que le tarif est en vigueur. */
    #[Groups(['tarif:lire'])]
    public function getFin(): ?\DateTimeImmutable { return $this->validite?->fin; }

    #[Groups(['tarif:ecrire'])]
    public function setFin(?\DateTimeImmutable $fin): void { $this->validite = new Periode($this->validite?->debut, $fin, false); }

    #[Assert\IsTrue(message: 'La date de fin précède la date de début.')]
    public function isPeriodeCoherente(): bool { return $this->validite?->coherente() ?? true; }
}
