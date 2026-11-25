<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\Doctrine\Periode;
use App\Filter\Egal;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use App\Validator\SansChevauchement;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Adhésion d'une personne à une association, dans une catégorie, sur une période (début et
 * fin inclus, fin vide = en cours). Passer de suiveur à full patch : clore l'une, ouvrir l'autre.
 */
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'adhesion', schema: 'adhesion')]
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('MEMBRES_LIRE')",
            parameters: [
                'membre' => new QueryParameter(filter: new Egal('membre'), description: 'UUID de la personne'),
                'association' => new QueryParameter(filter: new Egal('association'), description: 'UUID de l\'association'),
            ],
        ),
        new Get(security: "is_granted('MEMBRES_VOIR', object)"),
        new Post(securityPostDenormalize: "is_granted('MEMBRES_CREER_ADHESION', object)"),
        new Patch(
            security: "is_granted('MEMBRES_MODIFIER_ADHESION', object)",
            securityPostDenormalize: "is_granted('MEMBRES_MODIFIER_ADHESION', object)",
            denormalizationContext: ['groups' => ['adhesion:modifier']],
        ),
        new Delete(security: "is_granted('MEMBRES_ADMIN')"),  // saisie erronée ; une adhésion terminée se clôt avec « fin »
    ],
    normalizationContext: ['groups' => ['adhesion:lire']],
    denormalizationContext: ['groups' => ['adhesion:ecrire', 'adhesion:modifier']],
)]
#[SansChevauchement]
class Adhesion
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[Groups(['adhesion:lire'])]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'membre_id', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['adhesion:lire', 'adhesion:ecrire'])]
    private ?Personne $membre = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'association_id', nullable: false)]
    #[Assert\NotNull]
    #[Assert\Expression("value === null or value.getGenre() === 'association_jaxaj'", message: 'L\'adhésion se fait à une association JaXaJ.')]
    #[Groups(['adhesion:lire', 'adhesion:ecrire'])]
    private ?Organisation $association = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'categorie', referencedColumnName: 'code', nullable: false)]
    #[Assert\NotNull]
    #[Groups(['adhesion:lire', 'adhesion:ecrire', 'adhesion:modifier'])]
    private ?Categorie $categorie = null;

    #[ORM\Column(type: 'daterange')]
    private ?Periode $periode = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'au_titre_de')]
    #[Groups(['adhesion:lire', 'adhesion:ecrire', 'adhesion:modifier'])]
    private ?Organisation $auTitreDe = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['adhesion:lire', 'adhesion:ecrire', 'adhesion:modifier'])]
    private ?string $commentaire = null;

    /** Motif d'exemption de cotisation (statuts, décision du bureau…) : cotisations gratuites. */
    #[ORM\Column(nullable: true)]
    #[Groups(['adhesion:lire', 'adhesion:ecrire', 'adhesion:modifier'])]
    private ?string $exemption = null;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->periode = new Periode(new \DateTimeImmutable('today'));
    }

    public function getId(): Uuid { return $this->id; }
    public function getMembre(): ?Personne { return $this->membre; }
    public function setMembre(Personne $membre): void { $this->membre = $membre; }
    public function getAssociation(): ?Organisation { return $this->association; }
    public function setAssociation(Organisation $association): void { $this->association = $association; }
    public function getCategorie(): ?Categorie { return $this->categorie; }
    public function setCategorie(Categorie $categorie): void { $this->categorie = $categorie; }
    public function getAuTitreDe(): ?Organisation { return $this->auTitreDe; }
    public function setAuTitreDe(?Organisation $o): void { $this->auTitreDe = $o; }
    public function getCommentaire(): ?string { return $this->commentaire; }
    public function setCommentaire(?string $c): void { $this->commentaire = $c; }
    public function getPeriode(): ?Periode { return $this->periode; }
    public function getExemption(): ?string { return $this->exemption; }
    public function setExemption(?string $e): void { $this->exemption = '' === trim((string) $e) ? null : trim($e); }

    /** Les catégories du club vont aux associations du club, celles de l'événementiel à Productions. */
    #[Assert\IsTrue(message: 'Cette catégorie n\'existe pas dans cette association (club ou événementiel).')]
    public function isCategorieDuVolet(): bool
    {
        $volet = $this->association?->getVolet();

        return !$volet || !$this->categorie || $this->categorie->getVolet() === $volet;
    }

    #[Groups(['adhesion:lire'])]
    public function getDebut(): ?\DateTimeImmutable { return $this->periode?->debut; }

    #[Groups(['adhesion:ecrire', 'adhesion:modifier'])]
    public function setDebut(\DateTimeImmutable $debut): void { $this->periode = new Periode($debut, $this->periode?->fin, false); }

    /** Dernier jour inclus ; vide tant que l'adhésion est en cours. */
    #[Groups(['adhesion:lire'])]
    public function getFin(): ?\DateTimeImmutable { return $this->periode?->fin; }

    #[Groups(['adhesion:ecrire', 'adhesion:modifier'])]
    public function setFin(?\DateTimeImmutable $fin): void { $this->periode = new Periode($this->periode?->debut, $fin, false); }

    #[Assert\IsTrue(message: 'La date de fin précède la date de début.')]
    public function isPeriodeCoherente(): bool { return $this->periode?->coherente() ?? true; }

    /** Une adhésion qui porte des cotisations ne se supprime pas : ce serait effacer leur historique de paiement. */
    #[ORM\PreRemove]
    public function refuserSiCotisations(PreRemoveEventArgs $args): void
    {
        $n = $args->getObjectManager()->getRepository(Cotisation::class)->count(['adhesion' => $this]);
        if ($n > 0) {
            throw new \DomainException("Cette adhésion porte $n cotisation(s) : corrigez plutôt ses dates.");
        }
    }
}
