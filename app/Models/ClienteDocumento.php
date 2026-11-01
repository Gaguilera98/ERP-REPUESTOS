<?php

namespace App\Models;

use App\Casts\AtributosJsonCast;
use App\Clientes\NormalizarValoresDocumento;
use App\Models\Concerns\BelongsToTenantPool;
use Database\Factories\ClienteDocumentoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'cliente_id',
    'tipo_documento_identidad_id',
    'valores_json',
    'identidad',
])]
class ClienteDocumento extends Model
{
    /** @use HasFactory<ClienteDocumentoFactory> */
    use BelongsToTenantPool, HasFactory;

    protected $table = 'cliente_documentos';

    protected $attributes = [
        'valores_json' => '{}',
        'identidad' => '',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valores_json' => AtributosJsonCast::class,
        ];
    }

    /**
     * @return BelongsTo<Cliente, $this>
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /**
     * @return BelongsTo<TipoDocumentoIdentidad, $this>
     */
    public function tipoDocumentoIdentidad(): BelongsTo
    {
        return $this->belongsTo(TipoDocumentoIdentidad::class, 'tipo_documento_identidad_id');
    }

    protected static function booted(): void
    {
        static::saving(function (ClienteDocumento $documento): void {
            $normalizado = app(NormalizarValoresDocumento::class)->para($documento);
            $documento->valores_json = $normalizado['valores'];
            $documento->identidad = $normalizado['identidad'];
        });
    }
}
