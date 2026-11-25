<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\Filter\RechercheTexte;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Personne physique de l'annuaire. Lecture courte (nom, prénom, surnom) pour l'accueil ;
 * coordonnées et date de naissance pour la gestion (groupe personne:detail).
 */
#[ORM\Entity]
#[ORM\Table(name: 'personne', schema: 'annuaire')]
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('MEMBRES_LIRE')",
            order: ['nom' => 'ASC', 'prenom' => 'ASC'],
            parameters: ['q' => new QueryParameter(filter: new RechercheTexte(), description: 'Mots à chercher dans le nom, le prénom et le surnom, sans accents ni majuscules')],
        ),
        new Get(security: "is_granted('MEMBRES_VOIR', object)"),
        new Post(security: "is_granted('MEMBRES_ECRIRE_PERSONNE')"),
        new Patch(security: "is_granted('MEMBRES_MODIFIER_PERSONNE', object)"),  // celles dont on voit le détail
    ],
    normalizationContext: ['groups' => ['personne:lire']],
    denormalizationContext: ['groups' => ['personne:ecrire']],
)]
class Personne
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[Groups(['personne:lire'])]
    private Uuid $id;

    #[ORM\Column]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[Groups(['personne:lire', 'personne:ecrire'])]
    private string $nom = '';

    #[ORM\Column(nullable: true)]
    #[Assert\Length(max: 100)]
    #[Groups(['personne:lire', 'personne:ecrire'])]
    private ?string $prenom = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Length(max: 100)]
    #[Groups(['personne:lire', 'personne:ecrire'])]
    private ?string $surnom = null;

    /** personne, artiste (un artiste ou un groupe) ou structure (un club, une association). */
    #[ORM\Column]
    #[Assert\Choice(choices: ['personne', 'artiste', 'structure'], message: 'Type de fiche : personne, artiste ou structure.')]
    #[Groups(['personne:lire', 'personne:ecrire'])]
    private string $type = 'personne';

    #[ORM\Column(name: 'date_naissance', type: 'date_immutable', nullable: true)]
    #[Assert\LessThan('today')]
    #[Groups(['personne:detail', 'personne:ecrire'])]
    private ?\DateTimeImmutable $dateNaissance = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['personne:detail', 'personne:ecrire'])]
    private ?string $commentaire = null;

    /** Nom, prénom et surnom normalisés, calculés par PostgreSQL : sert à la recherche. */
    #[ORM\Column(insertable: false, updatable: false, generated: 'ALWAYS')]
    private ?string $recherche = null;

    /** @var Collection<int, Adresse> */
    #[ORM\OneToMany(targetEntity: Adresse::class, mappedBy: 'personne', cascade: ['persist'], orphanRemoval: true)]
    private Collection $adresses;

    /** @var Collection<int, Contact> */
    #[ORM\OneToMany(targetEntity: Contact::class, mappedBy: 'personne', cascade: ['persist'], orphanRemoval: true)]
    private Collection $contacts;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->adresses = new ArrayCollection();
        $this->contacts = new ArrayCollection();
    }

    public function getId(): Uuid { return $this->id; }
    public function getNom(): string { return $this->nom; }
    public function setNom(string $nom): void { $this->nom = trim($nom); }
    public function getPrenom(): ?string { return $this->prenom; }
    public function setPrenom(?string $prenom): void { $this->prenom = $this->vide($prenom); }
    public function getSurnom(): ?string { return $this->surnom; }
    public function setSurnom(?string $surnom): void { $this->surnom = $this->vide($surnom); }
    public function getDateNaissance(): ?\DateTimeImmutable { return $this->dateNaissance; }
    public function setDateNaissance(?\DateTimeImmutable $date): void { $this->dateNaissance = $date; }
    public function getCommentaire(): ?string { return $this->commentaire; }
    public function setCommentaire(?string $commentaire): void { $this->commentaire = $this->vide($commentaire); }

    /** Adresse principale : { ligne1, ligne2, code_postal, ville, pays }. */
    #[Groups(['personne:detail'])]
    #[Assert\Collection(fields: [
        'ligne1' => new Assert\Optional(new Assert\Length(max: 200)),
        'ligne2' => new Assert\Optional(new Assert\Length(max: 200)),
        'code_postal' => new Assert\Optional(new Assert\Length(max: 12)),
        'ville' => new Assert\Optional(new Assert\Length(max: 100)),
        'pays' => new Assert\Optional(new Assert\Regex('/^[A-Z]{2}$/', message: 'Code pays ISO à deux lettres (FR, ES…).')),
    ], allowMissingFields: true)]
    public function getAdresse(): ?array
    {
        $a = $this->principale($this->adresses);

        return $a?->versTableau();
    }

    #[Groups(['personne:ecrire'])]
    public function setAdresse(?array $adresse): void
    {
        $actuelle = $this->principale($this->adresses);
        if (null === $adresse) {
            $actuelle && $this->adresses->removeElement($actuelle);

            return;
        }
        if (!$actuelle) {
            $this->adresses->add($actuelle = new Adresse($this));
        }
        $actuelle->depuisTableau($adresse);
    }

    #[Groups(['personne:detail'])]
    #[Assert\Regex('/^\+?[0-9]{6,20}$/', message: 'Numéro de téléphone invalide.')]
    public function getTelephone(): ?string { return $this->contact('telephone'); }

    #[Groups(['personne:ecrire'])]
    public function setTelephone(?string $telephone): void
    {
        $this->definirContact('telephone', $telephone ? preg_replace('/[^\d+]/', '', $telephone) : null);
    }

    #[Groups(['personne:detail'])]
    #[Assert\Email]
    public function getEmail(): ?string { return $this->contact('email'); }

    #[Groups(['personne:ecrire'])]
    public function setEmail(?string $email): void { $this->definirContact('email', $this->vide($email)); }

    private function contact(string $type): ?string
    {
        foreach ($this->contacts as $c) {
            if ($c->getType() === $type && $c->estPrincipal()) {
                return $c->getValeur();
            }
        }

        return null;
    }

    private function definirContact(string $type, ?string $valeur): void
    {
        foreach ($this->contacts as $c) {
            if ($c->getType() === $type && $c->estPrincipal()) {
                null === $valeur ? $this->contacts->removeElement($c) : $c->setValeur($valeur);

                return;
            }
        }
        if (null !== $valeur) {
            $this->contacts->add(new Contact($this, $type, $valeur));
        }
    }

    private function principale(Collection $elements): ?Adresse
    {
        foreach ($elements as $e) {
            if ($e->estPrincipale()) {
                return $e;
            }
        }

        return null;
    }

    private function vide(?string $v): ?string
    {
        $v = null === $v ? null : trim($v);

        return '' === $v ? null : $v;
    }

    /** Présent seulement avec le détail : dit à l'interface que les coordonnées sont visibles. */
    #[Groups(['personne:detail'])]
    public function isDetail(): bool { return true; }

    public function getType(): string { return $this->type; }
    public function setType(string $t): void { $this->type = $t; }
}
