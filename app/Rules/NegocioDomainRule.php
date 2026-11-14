<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Stancl\Tenancy\Database\Models\Domain;

class NegocioDomainRule implements ValidationRule
{
    public function __construct(public int|string|null $ignoreDomainId = null) {}

    public static function normalize(string $domain): string
    {
        return strtolower(trim($domain));
    }

    public static function centralHost(): string
    {
        $domains = config('tenancy.central_domains', []);

        if (! is_array($domains) || $domains === []) {
            return 'localhost';
        }

        $first = $domains[0] ?? null;

        return is_string($first) && $first !== '' ? $first : 'localhost';
    }

    public static function hostCompleto(?string $subdomain): ?string
    {
        $subdomain = self::normalize((string) $subdomain);

        if ($subdomain === '') {
            return null;
        }

        return $subdomain.'.'.self::centralHost();
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $domain = self::normalize(is_string($value) ? $value : '');

        if ($domain === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $domain) !== 1) {
            $fail('El subdominio solo puede usar letras minúsculas, números y guiones.');

            return;
        }

        if (in_array($domain, ['localhost', 'www', 'admin'], true)) {
            $fail('Ese subdominio está reservado.');

            return;
        }

        $taken = Domain::query()
            ->where('domain', $domain)
            ->when(
                $this->ignoreDomainId !== null,
                fn ($query) => $query->whereKeyNot($this->ignoreDomainId),
            )
            ->exists();

        if ($taken) {
            $fail('Ese subdominio ya pertenece a otro negocio.');
        }
    }
}
