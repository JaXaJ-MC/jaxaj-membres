<?php

namespace App\Serializer;

use ApiPlatform\State\SerializerContextBuilderInterface;
use App\Entity\Personne;
use App\Security\Visibilite;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;

/**
 * Coordonnées et date de naissance d'une personne : seulement pour qui en voit le détail (voir
 * Visibilite). Une fiche : selon la personne ; une liste : pour qui voit tout le détail.
 */
#[AsDecorator('api_platform.serializer.context_builder')]
final class GroupesSelonDroits implements SerializerContextBuilderInterface
{
    public function __construct(
        #[AutowireDecorated] private SerializerContextBuilderInterface $decore,
        private Visibilite $visibilite,
    ) {}

    public function createFromRequest(Request $request, bool $normalization, ?array $extractedAttributes = null): array
    {
        $contexte = $this->decore->createFromRequest($request, $normalization, $extractedAttributes);
        $donnee = $request->attributes->get('data');
        $detail = $donnee instanceof Personne
            ? $this->visibilite->voitDetail((string) $donnee->getId())
            : $this->visibilite->toutDetail();
        if ($normalization && Personne::class === ($contexte['resource_class'] ?? null) && $detail) {
            $contexte['groups'][] = 'personne:detail';
        }

        return $contexte;
    }
}
