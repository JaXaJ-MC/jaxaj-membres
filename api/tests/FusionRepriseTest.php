<?php

namespace App\Tests;

use App\Service\Doublons;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Fusion d'une fiche issue de la reprise de la base Free : la table de correspondance
 * reprise.free_membre (anciens identifiants → fiches, sans suppression en cascade) suit la fiche
 * gardée, sinon la fiche absorbée ne peut pas être supprimée. Annulé à la fin (DAMA).
 */
class FusionRepriseTest extends KernelTestCase
{
    public function testLesAnciensIdentifiantsSuiventLaFicheGardee(): void
    {
        $b = static::getContainer()->get(Connection::class);
        $b->executeStatement('create schema if not exists reprise');
        $b->executeStatement('create table if not exists reprise.free_membre (ancien_id int primary key, entite_id uuid not null references annuaire.entite (id))');
        $prod = $b->fetchOne("select id from annuaire.organisation where nom = 'JaXaJ Productions'");
        $ancienne = $b->fetchOne("select annuaire.creer_personne('David', 'Loïc')");
        $gardee = $b->fetchOne("select annuaire.creer_personne('David', 'Loïc')");
        $b->executeStatement('insert into reprise.free_membre (ancien_id, entite_id) values (4242, :e)', ['e' => $ancienne]);
        $adh = $b->fetchOne("insert into adhesion.adhesion (membre_id, association_id, categorie, periode) values (:m, :o, 'public', '[2012-01-01,2013-01-01)') returning id",
            ['m' => $ancienne, 'o' => $prod]);
        $cot = $b->fetchOne("insert into adhesion.cotisation (adhesion_id, periode, montant) values (:a, '[2012-01-01,2013-01-01)', 20) returning id", ['a' => $adh]);
        $pai = $b->fetchOne("insert into adhesion.paiement (payeur_id, encaisse_par, montant, moyen) values (:m, :o, 20, 'autre') returning id", ['m' => $ancienne, 'o' => $prod]);
        $b->executeStatement('insert into adhesion.reglement (paiement_id, cotisation_id, montant) values (:p, :c, 20)', ['p' => $pai, 'c' => $cot]);

        static::getContainer()->get(Doublons::class)->fusionner($gardee, $ancienne);

        self::assertSame($gardee, $b->fetchOne('select entite_id from reprise.free_membre where ancien_id = 4242'));
        self::assertSame($gardee, $b->fetchOne('select membre_id from adhesion.adhesion where id = :a', ['a' => $adh]));
        self::assertSame($gardee, $b->fetchOne('select payeur_id from adhesion.paiement where id = :p', ['p' => $pai]));
        self::assertFalse($b->fetchOne('select 1 from annuaire.personne where id = :id', ['id' => $ancienne]));
    }
}
