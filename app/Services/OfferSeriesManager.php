<?php

namespace App\Services;

use App\Models\Company;
use App\Models\OfferGroup;
use App\Models\OfferSeries;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Séries de divulgação de ofertas (Pro). Grupo: o sistema cria "Nome #1" no modo só-admins e, quando ele
 * chega na capacidade, abre o #2 (o link fixo passa a levar pra ele). Canal: cria um novo ou usa um que a
 * loja já administra.
 */
class OfferSeriesManager
{
    public function __construct(private WahaClient $waha) {}

    /** O WhatsApp da loja precisa estar conectado — é ele que cria, renomeia e troca a foto dos grupos e posta. */
    public function ensureConnected(Company $company): string
    {
        $session = $company->whatsapp_session_name;
        $status = $session && $this->waha->isConfigured() ? ($this->waha->getSession($session)['status'] ?? null) : null;

        if ($status !== 'WORKING') {
            throw ValidationException::withMessages(['whatsapp' => 'Conecte o WhatsApp da loja em Integrações para mexer nos grupos e canais de ofertas.']);
        }

        return $session;
    }

    public function create(Company $company, array $data): OfferSeries
    {
        $session = $this->ensureConnected($company);

        $series = new OfferSeries([
            'company_id' => $company->id,
            'kind' => $data['kind'],
            'purpose' => $data['purpose'] ?? OfferSeries::PURPOSE_STORE,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'slug' => $this->freeSlug($data['name']),
            'capacity' => $data['capacity'] ?? 900,
            'backup_admin_phone' => $data['backup_admin_phone'] ?? null,
        ]);

        try {
            if ($series->isChannel()) {
                $channel = ($data['channel_jid'] ?? null)
                    ? $this->existingChannel($session, $data['channel_jid'])
                    : $this->waha->createChannel($session, $series->name, $series->description);
                $series->channel_jid = $channel['id'];
                $series->channel_invite = $channel['invite'];
                $series->save();
            } else {
                $series->save();
                $this->openGroup($series, $session, 1);
            }
        } catch (ValidationException $e) {
            $series->exists && $series->delete();
            throw $e;
        } catch (Throwable $e) {
            $series->exists && $series->delete();
            Log::warning("Ofertas: não foi possível criar a série (empresa {$company->id}): {$e->getMessage()}");
            throw ValidationException::withMessages(['whatsapp' => 'O WhatsApp não deixou criar agora. Tente de novo em alguns minutos.']);
        }

        return $series->load('groups');
    }

    /**
     * Confere quantas pessoas há em cada grupo e abre o próximo quando o atual lota. Roda a cada 15 min
     * (offers:check-groups) e pelo botão "Atualizar" da tela.
     */
    public function checkGroups(OfferSeries $series): void
    {
        if ($series->isChannel() || ! $series->active) {
            return;
        }

        $session = $series->company->whatsapp_session_name;
        if (! $session) {
            return;
        }

        $counts = $this->waha->groupParticipantCounts($session);

        foreach ($series->groups as $group) {
            if (isset($counts[$group->jid])) {
                $group->update(['participants_count' => $counts[$group->jid], 'participants_checked_at' => now()]);
            }
        }

        $current = $series->currentGroup();
        if ($current && $current->participants_count >= $series->capacity && ! $current->full_at) {
            $current->update(['full_at' => now()]);
            $this->openGroup($series, $session, $current->number + 1);
        }
    }

    private function openGroup(OfferSeries $series, string $session, int $number): OfferGroup
    {
        $jid = $this->waha->createGroup($session, $series->groupName($number));
        $this->waha->setupAnnouncementGroup($session, $jid, $series->description);

        $group = $series->groups()->create([
            'number' => $number,
            'jid' => $jid,
            'invite_url' => $this->waha->groupInviteUrl($session, $jid),
            'participants_count' => 1,
            'participants_checked_at' => now(),
        ]);

        $this->addBackupAdmin($series, $session, $group);

        // Foto padrão de todos os grupos: o novo #N já nasce com ela
        if ($series->image_path && $series->image_all_groups) {
            $this->setPicture($series, $session, $group);
        }

        return $group;
    }

    /**
     * Guarda a foto dos grupos: recorta o centro em quadrado e salva em JPEG 640×640 (o WhatsApp mostra a
     * foto do grupo redonda, e só aceita JPEG). Troca a anterior.
     */
    public function storePicture(OfferSeries $series, UploadedFile $file): void
    {
        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $source) {
            throw ValidationException::withMessages(['image' => 'Não foi possível ler a imagem. Use JPG, PNG ou WEBP.']);
        }

        $side = min(imagesx($source), imagesy($source));
        $canvas = imagecreatetruecolor(640, 640);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255)); // PNG transparente fica com fundo branco
        imagecopyresampled($canvas, $source, 0, 0, intdiv(imagesx($source) - $side, 2), intdiv(imagesy($source) - $side, 2), 640, 640, $side, $side);
        ob_start();
        imagejpeg($canvas, null, 88);
        $jpeg = (string) ob_get_clean();

        $path = "offers/{$series->company_id}/series-{$series->id}-".Str::lower(Str::random(6)).'.jpg';
        Storage::disk('public')->put($path, $jpeg);
        if ($series->image_path) {
            Storage::disk('public')->delete($series->image_path);
        }
        $series->update(['image_path' => $path]);
    }

    /**
     * Coloca a foto nos grupos: em todos (atuais e, depois, os próximos) quando é a padrão; senão só no
     * grupo atual. Devolve [quantos receberam, quantos falharam].
     *
     * @return array{0: int, 1: int}
     */
    public function applyPicture(OfferSeries $series): array
    {
        if ($series->isChannel() || ! $series->image_path) {
            return [0, 0];
        }

        $session = $this->ensureConnected($series->company);
        $targets = $series->image_all_groups ? $series->groups : collect([$series->currentGroup()])->filter();

        $ok = 0;
        foreach ($targets as $group) {
            $ok += $this->setPicture($series, $session, $group) ? 1 : 0;
        }

        return [$ok, $targets->count() - $ok];
    }

    /** Tira a foto dos grupos que estavam com ela e apaga o arquivo. */
    public function removePicture(OfferSeries $series): void
    {
        $session = $this->ensureConnected($series->company);

        foreach ($series->groups()->where('has_picture', true)->get() as $group) {
            try {
                $this->waha->deleteGroupPicture($session, $group->jid);
                $group->update(['has_picture' => false]);
            } catch (Throwable $e) {
                Log::warning("Ofertas: foto não removida do grupo {$group->jid}: {$e->getMessage()}");
            }
        }

        if ($series->image_path) {
            Storage::disk('public')->delete($series->image_path);
        }
        $series->update(['image_path' => null]);
    }

    /**
     * Novo nome da série: os grupos viram "Novo nome #1", "#2"... no WhatsApp. Canal só muda no sistema (o
     * nome do canal se troca pelo celular). Devolve [grupos renomeados, falhas].
     *
     * @return array{0: int, 1: int}
     */
    public function rename(OfferSeries $series, string $name): array
    {
        $session = $series->isChannel() ? null : $this->ensureConnected($series->company);
        $series->update(['name' => $name]);

        if (! $session) {
            return [0, 0];
        }

        $ok = 0;
        foreach ($series->groups as $group) {
            try {
                $this->waha->setGroupSubject($session, $group->jid, $series->groupName($group->number));
                $ok++;
            } catch (Throwable $e) {
                Log::warning("Ofertas: grupo {$group->jid} não renomeado: {$e->getMessage()}");
            }
        }

        return [$ok, $series->groups->count() - $ok];
    }

    private function setPicture(OfferSeries $series, string $session, OfferGroup $group): bool
    {
        try {
            $this->waha->setGroupPicture($session, $group->jid, (string) Storage::disk('public')->get($series->image_path));
            $group->update(['has_picture' => true]);

            return true;
        } catch (Throwable $e) {
            Log::warning("Ofertas: foto não aplicada no grupo {$group->jid}: {$e->getMessage()}");

            return false;
        }
    }

    /** Segundo admin informado (ou trocado) depois: entra nos grupos que ainda estão sem ele. */
    public function addBackupAdminToGroups(OfferSeries $series): void
    {
        $session = $series->company->whatsapp_session_name;
        if ($series->isChannel() || ! $series->backup_admin_phone || ! $session) {
            return;
        }

        foreach ($series->groups()->where('backup_admin_added', false)->get() as $group) {
            $this->addBackupAdmin($series, $session, $group);
        }
    }

    /**
     * Põe o segundo admin no grupo. Se não der (número sem WhatsApp, privacidade de grupos dele), o grupo
     * segue existindo e a tela mostra que ficou sem o reserva.
     */
    private function addBackupAdmin(OfferSeries $series, string $session, OfferGroup $group): void
    {
        if (! $series->backup_admin_phone) {
            return;
        }

        try {
            $chatId = $this->waha->chatIdFor($session, $series->backup_admin_phone);
            if ($chatId) {
                $this->waha->addGroupAdmin($session, $group->jid, $chatId);
                $group->update(['backup_admin_added' => true, 'participants_count' => $group->participants_count + 1]);
            }
        } catch (Throwable $e) {
            Log::warning("Ofertas: segundo admin não adicionado ao grupo {$group->jid}: {$e->getMessage()}");
        }
    }

    /** Nome do link a partir do nome da série ("ofertas-3d"); se já existir, ganha um final aleatório. */
    private function freeSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name), 50, '') ?: 'ofertas';
        $slug = $base;
        while (OfferSeries::where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        return $slug;
    }

    /** @return array{id: string, invite: string|null} */
    private function existingChannel(string $session, string $jid): array
    {
        $channel = collect($this->waha->listOwnChannels($session))->firstWhere('id', $jid);

        if (! $channel) {
            throw ValidationException::withMessages(['channel_jid' => 'Esse canal não foi encontrado entre os que a loja administra.']);
        }

        return ['id' => $channel['id'], 'invite' => $channel['invite']];
    }
}
