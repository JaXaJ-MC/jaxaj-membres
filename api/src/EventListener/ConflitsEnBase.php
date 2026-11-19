<?php

namespace App\EventListener;

use Doctrine\DBAL\Exception\DriverException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Les règles garanties par PostgreSQL (périodes qui se chevauchent, doublons, références
 * absentes) et les règles métier refusées deviennent des réponses lisibles, pas des erreurs 500.
 */
#[AsEventListener(priority: 10)]
final class ConflitsEnBase
{
    private const MESSAGES = [
        '23P01' => 'Chevauchement de périodes : une adhésion, une cotisation, un tarif ou un rôle existe déjà sur ces dates (un seul président, trésorier… à la fois par association).',
        '23505' => 'Doublon : cet élément existe déjà.',
        '23503' => 'Référence inconnue ou encore utilisée.',
        '23514' => 'Valeur refusée par une règle de la base.',
    ];

    public function __invoke(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        while ($e && !$e instanceof DriverException && !$e instanceof \DomainException) {
            $e = $e->getPrevious();
        }
        if ($e instanceof \DomainException) {
            $event->setResponse($this->probleme(422, $e->getMessage()));
        } elseif ($e instanceof DriverException && isset(self::MESSAGES[$e->getSQLState()])) {
            $event->setResponse($this->probleme(409, self::MESSAGES[$e->getSQLState()]));
        }
    }

    private function probleme(int $statut, string $detail): JsonResponse
    {
        return new JsonResponse(['title' => 409 === $statut ? 'Conflit' : 'Refusé', 'status' => $statut, 'detail' => $detail],
            $statut, ['Content-Type' => 'application/problem+json']);
    }
}
