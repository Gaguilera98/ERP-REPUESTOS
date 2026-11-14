<?php

namespace App\Catalogo;

use App\Enums\ModoMoneda;
use App\Models\NegocioConfiguracion;
use App\Models\ProductoVariante;
use App\Models\TenantUser;
use App\Models\TipoCambio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublicarTipoCambio
{
    public const PERMISO = 'Publicar:TipoCambio';

    public function __construct(private PreciosDeReferencia $precios) {}

    /** @return array{firma: string, cantidad: int, conflictos: int, cambios: list<array>, errores: list<array>} */
    public function previsualizar(mixed $valor): array
    {
        return DB::transaction(function () use ($valor): array {
            $configuracion = $this->bloquearConfiguracion();

            return $this->calcularVista($configuracion, $this->precios->validarCotizacion($valor, 'valor'));
        });
    }

    public function publicar(mixed $valor, string $firma, TenantUser $usuario): TipoCambio
    {
        abort_unless($usuario->is_active && (string) $usuario->tenant_id === (string) tenant()?->getTenantKey() && $usuario->can(self::PERMISO), 403);

        return DB::transaction(function () use ($valor, $firma, $usuario): TipoCambio {
            $configuracion = $this->bloquearConfiguracion();
            $valor = $this->precios->validarCotizacion($valor, 'valor');
            $vigente = $this->precios->vigente($configuracion->moneda->value);

            if ($vigente?->valor === $valor) {
                return $vigente;
            }

            $vista = $this->calcularVista($configuracion, $valor);

            if (! hash_equals($vista['firma'], $firma)) {
                throw ValidationException::withMessages(['valor' => 'El catálogo o la cotización cambió. Volvé a revisar los precios antes de publicar.']);
            }

            if ($vista['conflictos'] > 0) {
                throw ValidationException::withMessages(['valor' => 'Corregí los precios marcados antes de publicar.']);
            }

            foreach ($this->variantes()->lazyById() as $variante) {
                $nuevos = $this->convertir($variante, $valor);
                if ($this->cambia($variante, $nuevos)) {
                    ProductoVariante::query()->whereKey($variante->id)->update($nuevos);
                }
            }

            return TipoCambio::query()->create([
                'moneda_local' => $configuracion->moneda->value,
                'valor' => $valor,
                'user_id' => $usuario->id,
            ]);
        }, 3);
    }

    private function bloquearConfiguracion(): NegocioConfiguracion
    {
        $configuracion = NegocioConfiguracion::query()->lockForUpdate()->firstOrFail();

        abort_unless($configuracion->modo_moneda === ModoMoneda::ConDolar, 403);

        return $configuracion;
    }

    /** @return array{firma: string, cantidad: int, conflictos: int, cambios: list<array>, errores: list<array>} */
    private function calcularVista(NegocioConfiguracion $configuracion, string $valor): array
    {
        $vigente = $this->precios->vigente($configuracion->moneda->value);
        $firma = hash_init('sha256');
        hash_update($firma, json_encode([$configuracion->moneda->value, $vigente?->id, $valor], JSON_THROW_ON_ERROR));
        $cambios = [];
        $errores = [];
        $cantidad = 0;
        $conflictos = 0;

        foreach ($this->variantes()->lazyById() as $variante) {
            $nuevos = $this->convertir($variante, $valor);
            $problema = $this->precios->problema($nuevos['precio_venta'], $nuevos['precio_mayor']);
            hash_update($firma, json_encode([
                $variante->id, $variante->sku, $variante->precio_venta, $variante->precio_mayor,
                $variante->precio_venta_dolar, $variante->precio_mayor_dolar,
            ], JSON_THROW_ON_ERROR));

            $fila = [
                'sku' => $variante->sku,
                'menor_antes' => $variante->precio_venta,
                'mayor_antes' => $variante->precio_mayor,
                'menor_despues' => $nuevos['precio_venta'],
                'mayor_despues' => $nuevos['precio_mayor'],
                'problema' => $problema,
            ];

            if ($problema !== null) {
                $conflictos++;
                if (count($errores) < 30) {
                    $errores[] = $fila;
                }
            }

            if ($this->cambia($variante, $nuevos)) {
                $cantidad++;
                if (count($cambios) < 30) {
                    $cambios[] = $fila;
                }
            }
        }

        return ['firma' => hash_final($firma), 'cantidad' => $cantidad, 'conflictos' => $conflictos, 'cambios' => $cambios, 'errores' => $errores];
    }

    /** @param array{precio_venta: string|null, precio_mayor: string|null} $nuevos */
    private function cambia(ProductoVariante $variante, array $nuevos): bool
    {
        return $variante->precio_venta !== $nuevos['precio_venta'] || $variante->precio_mayor !== $nuevos['precio_mayor'];
    }

    private function variantes(): Builder
    {
        return ProductoVariante::query()
            ->select(['id', 'sku', 'precio_venta', 'precio_mayor', 'precio_venta_dolar', 'precio_mayor_dolar'])
            ->where(fn (Builder $query) => $query->whereNotNull('precio_venta_dolar')->orWhereNotNull('precio_mayor_dolar'));
    }

    /** @return array{precio_venta: string|null, precio_mayor: string|null} */
    private function convertir(ProductoVariante $variante, string $valor): array
    {
        return $this->precios->convertir(
            $variante->precio_venta,
            $variante->precio_mayor,
            $variante->precio_venta_dolar,
            $variante->precio_mayor_dolar,
            $valor,
        );
    }
}
