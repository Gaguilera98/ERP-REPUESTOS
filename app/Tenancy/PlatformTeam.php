<?php

namespace App\Tenancy;

final class PlatformTeam
{
    /**
     * Equipo de Spatie para los roles de la plataforma.
     * Vive en la base central y no es un tenant.
     */
    public const string Id = 'platform';
}
