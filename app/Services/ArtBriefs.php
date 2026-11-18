<?php

namespace App\Services;

use App\Models\ArtBrief;
use App\Models\ArtBriefTemplate;
use App\Models\Company;
use App\Models\Product;
use App\Models\Quote;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Perguntas do briefing da arte. Cada loja começa com as do seu nicho (Company::SEGMENTS) e pode ligar, desligar,
 * editar, reordenar, apagar e criar perguntas (companies.art_brief_questions; null = padrão do nicho).
 *
 * Pergunta: key, label, hint, type, required, enabled e, no tipo "choice", options + multiple.
 * Tipos e o formato da resposta:
 *  - text / long_text: texto · date: AAAA-MM-DD · colors: ["#RRGGBB", ...] · choice: ["opção", ...]
 *  - names: ["Ana", "João", ...] · sizes: [{size, quantity}] · placements: [{place, description}]
 *  - files: {links: ["https://..."]} (os arquivos anexados ficam em art_brief_files, ligados pela key)
 */
class ArtBriefs
{
    public const TYPES = ['text', 'long_text', 'colors', 'choice', 'date', 'names', 'sizes', 'placements', 'files'];

    public const MAX_QUESTIONS = 30;

    public const MAX_FILES = 10;

    private const COMMON_REFERENCES = ['key' => 'referencias', 'label' => 'Tem referências?', 'hint' => 'Fotos, prints, PDFs ou links (Pinterest, Instagram...) do que você imagina.', 'type' => 'files'];

    private const COMMON_NAMES = ['key' => 'nomes', 'label' => 'Nomes individuais', 'hint' => 'Um nome por unidade (lembrancinhas, kits, times). Deixe em branco se não precisar.', 'type' => 'names'];

    /** Perguntas sugeridas por nicho. Todas começam ligadas; obrigatória só o que a loja não consegue fazer sem. */
    public static function defaults(?string $segment): array
    {
        $questions = match ($segment) {
            'stationery' => [
                ['key' => 'tema', 'label' => 'Qual o tema da festa ou do pedido?', 'hint' => 'Ex.: Safari, Frozen, Chá de bebê floral, personagem favorito...', 'type' => 'text', 'required' => true],
                ['key' => 'aniversariante', 'label' => 'Nome e idade do aniversariante', 'hint' => 'Do jeito que deve aparecer na arte. Ex.: Maria · 5 anos', 'type' => 'text'],
                ['key' => 'data_evento', 'label' => 'Data do evento', 'hint' => 'Para convites e para a gente se organizar com o prazo.', 'type' => 'date'],
                ['key' => 'local_evento', 'label' => 'Local e horário do evento', 'hint' => 'Só se for convite. Ex.: Salão Alegria, às 16h.', 'type' => 'text'],
                ['key' => 'cores', 'label' => 'Quais cores você quer?', 'hint' => 'Escolha a cor principal e outras se quiser.', 'type' => 'colors'],
                ['key' => 'texto', 'label' => 'Texto ou frase da arte', 'hint' => 'Escreva exatamente como deve ficar (com acentos).', 'type' => 'long_text'],
                self::COMMON_NAMES,
                self::COMMON_REFERENCES,
                ['key' => 'observacoes', 'label' => 'Mais algum detalhe?', 'hint' => 'O que você não quer, prazo, outras ideias...', 'type' => 'long_text'],
            ],
            Company::SEGMENT_3D => [
                ['key' => 'peca', 'label' => 'O que é a peça e para que vai usar?', 'hint' => 'Ex.: chaveiro de lembrancinha, suporte de fone, peça de reposição...', 'type' => 'long_text', 'required' => true],
                ['key' => 'uso', 'label' => 'Tipo de uso', 'type' => 'choice', 'options' => ['Decoração', 'Funcional (vai ter uso/esforço)', 'Presente/lembrancinha'], 'multiple' => false],
                ['key' => 'tamanho', 'label' => 'Tamanho ou medidas', 'hint' => 'Aproximado já ajuda. Ex.: 5 cm de altura, cabe na palma da mão.', 'type' => 'text'],
                ['key' => 'encaixe', 'label' => 'Precisa encaixar em alguma coisa?', 'hint' => 'Se sim, mande a medida do encaixe (em mm, se possível).', 'type' => 'text'],
                ['key' => 'cores', 'label' => 'Cores da peça', 'hint' => 'Uma cor só ou várias.', 'type' => 'colors'],
                ['key' => 'acabamento', 'label' => 'Acabamento', 'type' => 'choice', 'options' => ['Fosco', 'Brilhante', 'Pintado à mão', 'Tanto faz'], 'multiple' => false],
                ['key' => 'texto', 'label' => 'Tem texto ou nome na peça?', 'hint' => 'Escreva exatamente como deve ficar.', 'type' => 'text'],
                self::COMMON_NAMES,
                ['key' => 'referencias', 'label' => 'Tem referência ou modelo?', 'hint' => 'Foto, desenho, arquivo STL/3MF ou link do modelo.', 'type' => 'files'],
            ],
            'bakery' => [
                ['key' => 'data_entrega', 'label' => 'Data da entrega ou retirada', 'type' => 'date', 'required' => true],
                ['key' => 'tema', 'label' => 'Tema e decoração', 'hint' => 'Ex.: Fundo do mar, flores, minimalista...', 'type' => 'text'],
                ['key' => 'cores', 'label' => 'Cores da decoração', 'type' => 'colors'],
                ['key' => 'sabor_massa', 'label' => 'Sabor da massa', 'type' => 'text'],
                ['key' => 'recheio', 'label' => 'Recheio', 'type' => 'text'],
                ['key' => 'pessoas', 'label' => 'Para quantas pessoas?', 'hint' => 'Ou o tamanho que você quer.', 'type' => 'text'],
                ['key' => 'texto', 'label' => 'Texto no bolo ou nos doces', 'hint' => 'Escreva exatamente como deve ficar.', 'type' => 'text'],
                ['key' => 'restricoes', 'label' => 'Alguma restrição ou alergia?', 'type' => 'choice', 'options' => ['Sem lactose', 'Sem glúten', 'Sem açúcar', 'Alergia a amendoim/castanhas', 'Nenhuma'], 'multiple' => true],
                self::COMMON_REFERENCES,
            ],
            'crafts' => [
                ['key' => 'tema', 'label' => 'Tema e estilo', 'hint' => 'Ex.: delicado, divertido, religioso, personagem...', 'type' => 'text'],
                ['key' => 'cores', 'label' => 'Quais cores você quer?', 'type' => 'colors'],
                ['key' => 'locais', 'label' => 'Descreva a arte de cada parte', 'hint' => 'Um bloco por local (frente, verso, manga, tampa...): estilo, elementos, mensagem e o que NÃO quer.', 'type' => 'placements'],
                ['key' => 'texto', 'label' => 'Texto, nome ou frase', 'hint' => 'Escreva exatamente como deve ficar.', 'type' => 'text'],
                ['key' => 'tamanhos', 'label' => 'Tamanhos e quantidades', 'hint' => 'Para roupas: quantas de cada tamanho.', 'type' => 'sizes'],
                self::COMMON_NAMES,
                self::COMMON_REFERENCES,
            ],
            default => [
                ['key' => 'descricao', 'label' => 'Descreva o que você imagina', 'hint' => 'Estilo, elementos, mensagem e o que NÃO quer.', 'type' => 'long_text', 'required' => true],
                ['key' => 'cores', 'label' => 'Quais cores você quer?', 'type' => 'colors'],
                ['key' => 'texto', 'label' => 'Texto, nome ou frase', 'hint' => 'Escreva exatamente como deve ficar.', 'type' => 'text'],
                self::COMMON_NAMES,
                self::COMMON_REFERENCES,
            ],
        };

        return array_map(fn (array $q) => self::fill($q), $questions);
    }

    /** As perguntas da loja (as dela, ou as do nicho), incluindo as desligadas. */
    public static function questions(Company $company): array
    {
        $saved = $company->art_brief_questions;

        return is_array($saved) && $saved !== [] ? array_map(fn ($q) => self::fill($q), $saved) : self::defaults($company->segment);
    }

    /** Só as ligadas: é o que vai no link do cliente. */
    public static function activeQuestions(Company $company): array
    {
        return array_values(array_filter(self::questions($company), fn ($q) => $q['enabled']));
    }

    /** Modelos de briefing da loja (perguntas com nome, ligados aos produtos). */
    public static function templates(Company $company): array
    {
        return ArtBriefTemplate::where('company_id', $company->id)->orderBy('name')->get()
            ->map(fn (ArtBriefTemplate $t) => ['id' => $t->id, 'name' => $t->name, 'questions' => array_map(fn ($q) => self::fill($q), $t->questions ?? [])])
            ->all();
    }

    /** Produtos do pedido que pedem briefing (cada um com o seu modelo, ou o padrão da loja). */
    public static function briefProducts(Quote $quote): \Illuminate\Support\Collection
    {
        $ids = $quote->items()->where('type', 'product')->whereNotNull('product_id')->orderBy('id')->pluck('product_id')->unique();

        return Product::where('company_id', $quote->company_id)->whereIn('id', $ids)->where('art_brief_enabled', true)->get()
            ->sortBy(fn (Product $p) => $ids->search($p->id))->values();
    }

    /**
     * Perguntas do link de um pedido: as dos produtos dele (o modelo de cada um, ou o padrão da loja). Sem produto que
     * peça briefing, as perguntas padrão da loja. Com modelos diferentes no mesmo pedido, as perguntas vêm em seções
     * (o nome do modelo), com a key prefixada para não misturar respostas.
     */
    public static function questionsForQuote(Quote $quote): array
    {
        $company = $quote->company;
        $groups = [];
        foreach (self::briefProducts($quote) as $product) {
            $groupKey = $product->art_brief_template_id ?: 'default';
            $groups[$groupKey] ??= $groupKey;
        }
        if ($groups === []) {
            return self::activeQuestions($company);
        }

        $templates = ArtBriefTemplate::where('company_id', $company->id)->whereIn('id', array_filter(array_keys($groups), 'is_int'))->get()->keyBy('id');
        $sets = [];
        foreach (array_keys($groups) as $groupKey) {
            $template = $groupKey === 'default' ? null : $templates->get($groupKey);
            $questions = $template ? array_map(fn ($q) => self::fill($q), $template->questions ?? []) : self::questions($company);
            $sets[] = ['name' => $template?->name ?? 'Perguntas gerais', 'questions' => array_values(array_filter($questions, fn ($q) => $q['enabled']))];
        }
        if (count($sets) === 1) {
            return $sets[0]['questions'];
        }

        $out = [];
        foreach ($sets as $i => $set) {
            foreach ($set['questions'] as $q) {
                $out[] = ['key' => 's'.($i + 1).'_'.$q['key'], 'section' => $set['name']] + $q;
            }
        }

        return $out;
    }

    /** Abre (ou atualiza, se ainda não respondido) o briefing do pedido com as perguntas certas. */
    public static function openFor(Quote $quote): ArtBrief
    {
        $questions = self::questionsForQuote($quote);
        $brief = $quote->artBriefs()->first();
        if ($brief && $brief->isOpen()) {
            $brief->update(['questions' => $questions]);

            return $brief;
        }

        return ArtBrief::create([
            'company_id' => $quote->company_id,
            'quote_id' => $quote->id,
            'token' => Str::random(40),
            'status' => ArtBrief::PENDING,
            'questions' => $questions,
        ]);
    }

    /**
     * Confere e limpa a lista que o lojista salvou. Pergunta sem key ganha uma nova (as respostas antigas ficam ligadas
     * à key da pergunta, por isso ela nunca muda depois de criada).
     *
     * @throws ValidationException
     */
    public static function normalizeQuestions(array $input): array
    {
        $out = [];
        $keys = [];
        foreach (array_values($input) as $i => $q) {
            $label = trim((string) ($q['label'] ?? ''));
            $type = (string) ($q['type'] ?? '');
            if ($label === '') {
                throw ValidationException::withMessages(["questions.$i.label" => 'Escreva o texto da pergunta '.($i + 1).'.']);
            }
            if (! in_array($type, self::TYPES, true)) {
                throw ValidationException::withMessages(["questions.$i.type" => 'Tipo de resposta inválido na pergunta "'.$label.'".']);
            }
            $key = preg_replace('/[^a-z0-9_]/', '', Str::lower((string) ($q['key'] ?? ''))) ?: 'p_'.Str::lower(Str::random(8));
            while (isset($keys[$key])) {
                $key = 'p_'.Str::lower(Str::random(8));
            }
            $keys[$key] = true;
            $options = [];
            if ($type === 'choice') {
                $options = array_values(array_unique(array_filter(array_map(fn ($o) => mb_substr(trim((string) $o), 0, 80), (array) ($q['options'] ?? [])), fn ($o) => $o !== '')));
                if (count($options) < 2) {
                    throw ValidationException::withMessages(["questions.$i.options" => 'A pergunta "'.$label.'" precisa de pelo menos duas opções.']);
                }
            }
            $out[] = self::fill([
                'key' => mb_substr($key, 0, 40),
                'label' => mb_substr($label, 0, 160),
                'hint' => mb_substr(trim((string) ($q['hint'] ?? '')), 0, 300),
                'type' => $type,
                'required' => (bool) ($q['required'] ?? false),
                'enabled' => (bool) ($q['enabled'] ?? true),
                'options' => array_slice($options, 0, 20),
                'multiple' => (bool) ($q['multiple'] ?? false),
            ]);
        }
        if (count($out) > self::MAX_QUESTIONS) {
            throw ValidationException::withMessages(['questions' => 'No máximo '.self::MAX_QUESTIONS.' perguntas.']);
        }
        if (! collect($out)->contains(fn ($q) => $q['enabled'])) {
            throw ValidationException::withMessages(['questions' => 'Deixe pelo menos uma pergunta ligada.']);
        }

        return $out;
    }

    /**
     * Respostas do cliente, conferidas pergunta a pergunta (as obrigatórias precisam ter resposta). Só ficam as respostas
     * das perguntas deste briefing.
     *
     * @throws ValidationException
     */
    public static function cleanAnswers(ArtBrief $brief, array $input): array
    {
        $answers = [];
        $hasFiles = $brief->files()->pluck('question_key')->flip();

        foreach ($brief->questions as $q) {
            $key = $q['key'];
            $value = self::cleanValue($q, $input[$key] ?? null);
            $empty = $value === null && ! ($q['type'] === 'files' && isset($hasFiles[$key]));
            if ($q['required'] && $empty) {
                throw ValidationException::withMessages(["answers.$key" => 'Responda: '.$q['label']]);
            }
            if ($value !== null) {
                $answers[$key] = $value;
            }
        }

        return $answers;
    }

    private static function cleanValue(array $q, mixed $v): mixed
    {
        $text = fn ($s, $max) => ($t = trim(mb_substr((string) $s, 0, $max))) === '' ? null : $t;
        $list = fn ($items, $max, $len) => array_values(array_filter(array_map(fn ($s) => $text($s, $len), array_slice(is_array($items) ? $items : [], 0, $max))));

        $value = match ($q['type']) {
            'text' => $text($v, 300),
            'long_text' => $text($v, 3000),
            'date' => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null,
            'colors' => array_values(array_unique(array_filter(array_map(
                fn ($c) => is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? Str::upper($c) : null,
                array_slice(is_array($v) ? $v : [], 0, 8)
            )))),
            'choice' => array_values(array_intersect($q['options'], is_array($v) ? $v : [(string) $v])),
            'names' => $list($v, 500, 80),
            'sizes' => array_values(array_filter(array_map(fn ($row) => is_array($row) && ($size = $text($row['size'] ?? '', 20)) && (int) ($row['quantity'] ?? 0) > 0
                ? ['size' => $size, 'quantity' => min(9999, (int) $row['quantity'])] : null, array_slice(is_array($v) ? $v : [], 0, 30)))),
            'placements' => array_values(array_filter(array_map(fn ($row) => is_array($row) && ($desc = $text($row['description'] ?? '', 2000))
                ? ['place' => $text($row['place'] ?? '', 60), 'description' => $desc] : null, array_slice(is_array($v) ? $v : [], 0, 10)))),
            'files' => ($links = array_values(array_filter(array_map(
                fn ($l) => is_string($l) && filter_var(trim($l), FILTER_VALIDATE_URL) && preg_match('#^https?://#i', trim($l)) ? mb_substr(trim($l), 0, 500) : null,
                array_slice(is_array($v['links'] ?? null) ? $v['links'] : [], 0, 10)
            )))) ? ['links' => $links] : null,
            default => null,
        };
        if ($q['type'] === 'choice' && ! $q['multiple'] && is_array($value)) {
            $value = array_slice($value, 0, 1);
        }

        return $value === [] ? null : $value;
    }

    private static function fill(array $q): array
    {
        return [
            'key' => (string) $q['key'],
            'label' => (string) $q['label'],
            'hint' => (string) ($q['hint'] ?? ''),
            'type' => (string) $q['type'],
            'required' => (bool) ($q['required'] ?? false),
            'enabled' => (bool) ($q['enabled'] ?? true),
            'options' => array_values((array) ($q['options'] ?? [])),
            'multiple' => (bool) ($q['multiple'] ?? false),
        ] + (isset($q['section']) && $q['section'] !== '' ? ['section' => (string) $q['section']] : []);
    }
}
