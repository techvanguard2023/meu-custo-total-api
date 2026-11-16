<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Prova de arte de um pedido: arquivos que o cliente confere e aprova (ou pede ajuste) por um link público.
 * Aprovada, guarda quem aprovou (nome digitado), quando, o IP e o texto do termo que ele leu.
 */
class ArtProof extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const CHANGES_REQUESTED = 'changes_requested';

    public const SUPERSEDED = 'superseded';

    /** Termo usado quando a loja não escreveu o próprio (Configurações → Aprovação de arte). */
    public const DEFAULT_TERMS = "Ao aprovar, declaro que conferi a arte enviada — textos, nomes, datas, cores, tamanhos e imagens — e que está tudo correto.\n\nA produção seguirá exatamente o que foi aprovado. Depois da aprovação, mudanças podem ter custo adicional e alterar o prazo, e erros que já estavam na arte aprovada não são de responsabilidade da loja.\n\nAs cores podem variar um pouco entre a tela e o produto final (material, tinta e impressão).";

    protected $fillable = [
        'company_id', 'quote_id', 'token', 'version', 'status', 'message', 'model_url', 'choose_one', 'chosen_file_id', 'terms',
        'viewed_at', 'responded_at', 'approver_name', 'approver_ip', 'approver_user_agent', 'customer_comment',
    ];

    /** Nas listas (produção, vendas) só entram status e versão: termo, IP e link ficam de fora. */
    protected $hidden = ['terms', 'token', 'approver_ip', 'approver_user_agent', 'customer_comment', 'message', 'model_url'];

    protected $casts = [
        'version' => 'integer',
        'choose_one' => 'boolean',
        'chosen_file_id' => 'integer',
        'viewed_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Os arquivos saem do disco junto com a prova
        static::deleting(function (ArtProof $proof) {
            $paths = $proof->files()->pluck('path')->all();
            if ($paths !== []) {
                Storage::disk('public')->delete($paths);
            }
        });
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(ArtProofFile::class)->orderBy('position')->orderBy('id');
    }

    /** A opção que o cliente escolheu, quando a loja mandou várias artes para ele escolher uma. */
    public function chosenFile(): BelongsTo
    {
        return $this->belongsTo(ArtProofFile::class, 'chosen_file_id');
    }

    /** Número da opção escolhida (1, 2, 3...), na ordem em que a loja mandou. */
    public function chosenOptionNumber(): ?int
    {
        if (! $this->chosen_file_id) {
            return null;
        }
        $index = $this->files->search(fn (ArtProofFile $f) => $f->id === $this->chosen_file_id);

        return $index === false ? null : $index + 1;
    }

    public function isOpen(): bool
    {
        return $this->status === self::PENDING;
    }

    /** Endereço que o cliente abre (no domínio próprio da loja, quando ela tem). */
    public function publicUrl(): string
    {
        return $this->company->publicOrigin().'/arte/'.$this->token;
    }
}
