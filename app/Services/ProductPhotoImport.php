<?php

namespace App\Services;

use App\Exceptions\PhotoDownloadFailed;
use App\Models\Product;
use App\Support\Background;
use App\Support\PublicUrl;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Psr\Http\Message\UriInterface;
use Throwable;

/**
 * Fotos dos produtos por link, na importação de planilha. Os produtos são gravados na hora; as fotos são baixadas
 * depois (fila ou logo após a resposta), um produto por tarefa, e a tela acompanha pelo "lote" (status no cache).
 *
 * Segurança: só endereços http(s) públicos (cada redirecionamento também), até 8 MB, e só imagem de verdade
 * (JPG, PNG ou WebP, conferido pelo conteúdo). Links de compartilhamento do Google Drive e do Dropbox viram o
 * link do arquivo.
 */
class ProductPhotoImport
{
    public const MAX_PER_PRODUCT = 8;

    private const MAX_BYTES = 8 * 1024 * 1024;

    private const MAX_FAILURES_LISTED = 300;

    private const TTL_HOURS = 24;

    /**
     * Links de uma célula: separados por espaço, quebra de linha, ";", "|" ou ", " antes de outro http.
     *
     * @return array{urls: string[], invalid: string[]}
     */
    public function parseLinks(?string $raw): array
    {
        $urls = [];
        $invalid = [];
        foreach (preg_split('/[\s;|]+|,(?=\s*https?:\/\/)/i', trim((string) $raw)) ?: [] as $part) {
            $part = trim($part, " \t,\"'");
            if ($part === '') {
                continue;
            }
            if (! preg_match('~^https?://[^\s/$.?#][^\s]*$~i', $part) || strlen($part) > 2048) {
                $invalid[] = Str::limit($part, 60);

                continue;
            }
            $urls[] = $this->direct($part);
        }

        return ['urls' => array_values(array_unique($urls)), 'invalid' => $invalid];
    }

    /** Link de compartilhamento → link do arquivo (Google Drive, Dropbox). Os demais ficam como vieram. */
    public function direct(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === 'drive.google.com') {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $id = preg_match('~/file/d/([\w-]+)~', $url, $m) ? $m[1] : ($query['id'] ?? null);
            if ($id && preg_match('/^[\w-]+$/', $id)) {
                return "https://drive.google.com/uc?export=download&id={$id}";
            }
        }
        if ($host === 'dropbox.com' || str_ends_with($host, '.dropbox.com')) {
            $url = preg_replace('/([?&])dl=0(&|$)/', '$1raw=1$2', $url);

            return str_contains($url, 'raw=1') || str_contains($url, 'dl=1') ? $url : $url.(str_contains($url, '?') ? '&' : '?').'raw=1';
        }

        return $url;
    }

    /**
     * Começa o lote: um item por produto ({product_id, line, label, urls}). Devolve o id do lote.
     *
     * @param  array<int, array{product_id: int, line: int, label: string, urls: string[]}>  $items
     */
    public function start(int $companyId, array $items): string
    {
        $batch = (string) Str::uuid();
        Cache::put($this->key($companyId, $batch), [
            'total' => count($items),
            'done' => 0,
            'saved' => 0,
            'failed' => [],
            'failed_count' => 0,
        ], now()->addHours(self::TTL_HOURS));

        foreach ($items as $item) {
            Background::run(fn () => app(ProductPhotoImport::class)->download($companyId, $batch, $item));
        }

        return $batch;
    }

    public function status(int $companyId, string $batch): ?array
    {
        $status = Cache::get($this->key($companyId, $batch));

        return $status ? $status + ['finished' => $status['done'] >= $status['total']] : null;
    }

    /** Baixa as fotos de um produto (até completar 8 com as que ele já tem) e marca no lote. */
    public function download(int $companyId, string $batch, array $item): void
    {
        $saved = 0;
        $failures = [];
        try {
            $product = Product::where('company_id', $companyId)->withCount('images')->find($item['product_id']);
            if ($product) {
                $room = max(0, self::MAX_PER_PRODUCT - $product->images_count);
                $position = $product->images_count;
                foreach (array_slice($item['urls'], 0, $room) as $url) {
                    try {
                        $stored = app(ImageOptimizer::class)->storeProductImage($this->fetch($url));
                        // O disco não avisa quando não grava (throw = false): sem o arquivo, a foto ficaria quebrada
                        if (! Storage::disk('public')->exists($stored['path'])) {
                            throw new PhotoDownloadFailed('não deu para salvar a foto no servidor');
                        }
                        $product->images()->create(['path' => $stored['path'], 'share_path' => $stored['share_path'], 'position' => $position++]);
                        $saved++;
                    } catch (Throwable $e) {
                        $failures[] = ['line' => $item['line'], 'label' => $item['label'], 'url' => Str::limit($url, 120), 'reason' => $e instanceof PhotoDownloadFailed ? $e->getMessage() : 'não deu para baixar'];
                        if (! $e instanceof PhotoDownloadFailed) {
                            Log::info('Importação de fotos: '.Str::limit($e->getMessage(), 200));
                        }
                    }
                }
                if (count($item['urls']) > $room) {
                    $failures[] = ['line' => $item['line'], 'label' => $item['label'], 'url' => '', 'reason' => 'passou de '.self::MAX_PER_PRODUCT.' fotos por produto; as demais ficaram de fora'];
                }
                if ($saved) {
                    // Foto não muda o produto em si: o catálogo guardado precisa saber
                    CatalogCache::forget($companyId);
                }
            }
        } finally {
            $this->record($companyId, $batch, $saved, $failures);
        }
    }

    /** Baixa e confere que é imagem. Erro = PhotoDownloadFailed com o motivo em português (vai para a tela). */
    public function fetch(string $url): string
    {
        $this->assertPublic($url);
        try {
            $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; MeuCustoTotal/1.0; +https://meucustototal.com.br)', 'Accept' => 'image/*'])
                ->timeout(20)
                ->withOptions([
                    RequestOptions::PROGRESS => function ($total, $downloaded) {
                        if ($total > self::MAX_BYTES || $downloaded > self::MAX_BYTES) {
                            throw new PhotoDownloadFailed('imagem maior que 8 MB');
                        }
                    },
                    RequestOptions::ALLOW_REDIRECTS => [
                        'max' => 5,
                        'on_redirect' => fn ($request, $response, UriInterface $uri) => $this->assertPublic((string) $uri),
                    ],
                ])
                ->get($url);
        } catch (Throwable $e) {
            // O motivo pode vir embrulhado pelo cliente HTTP (limite de tamanho, redirecionamento para endereço interno)
            for ($cause = $e; $cause; $cause = $cause->getPrevious()) {
                if ($cause instanceof PhotoDownloadFailed) {
                    throw new PhotoDownloadFailed($cause->getMessage());
                }
            }
            throw new PhotoDownloadFailed(str_contains(strtolower($e->getMessage()), 'timed out') ? 'o site demorou demais para responder' : 'o link não abriu');
        }

        if (! $response->successful()) {
            throw new PhotoDownloadFailed("o link não abriu (erro {$response->status()})");
        }
        $bytes = $response->body();
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new PhotoDownloadFailed('imagem maior que 8 MB');
        }
        $mime = (@getimagesizefromstring($bytes))['mime'] ?? null;
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new PhotoDownloadFailed(str_contains(strtolower($response->header('Content-Type')), 'text/html')
                ? 'o link abre uma página, não a imagem (no Google Drive, compartilhe com "qualquer pessoa com o link")'
                : 'não é uma imagem JPG, PNG ou WebP');
        }

        return $bytes;
    }

    private function assertPublic(string $url): void
    {
        if (PublicUrl::problem($url) !== null) {
            throw new PhotoDownloadFailed('endereço não permitido ou inexistente');
        }
    }

    private function record(int $companyId, string $batch, int $saved, array $failures): void
    {
        $key = $this->key($companyId, $batch);
        Cache::lock("{$key}:lock", 10)->block(10, function () use ($key, $saved, $failures) {
            $status = Cache::get($key);
            if (! $status) {
                return;
            }
            $status['done']++;
            $status['saved'] += $saved;
            $status['failed_count'] += count($failures);
            $status['failed'] = array_slice(array_merge($status['failed'], $failures), 0, self::MAX_FAILURES_LISTED);
            Cache::put($key, $status, now()->addHours(self::TTL_HOURS));
        });
    }

    private function key(int $companyId, string $batch): string
    {
        return "import-photos:{$companyId}:{$batch}";
    }
}
