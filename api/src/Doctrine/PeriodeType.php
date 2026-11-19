<?php

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/** Type Doctrine « daterange » (PostgreSQL) ↔ Periode. */
final class PeriodeType extends Type
{
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'daterange';
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?Periode
    {
        return null === $value ? null : Periode::depuisPostgres($value);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        return $value instanceof Periode ? $value->versPostgres() : $value;
    }
}
