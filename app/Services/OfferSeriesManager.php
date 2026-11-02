<?php

namespace App\Services;

use App\Models\Company;
use App\Models\OfferGroup;
use App\Models\OfferSeries;
use Illuminate\Support\Facades\Log;
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

    /** O WhatsApp da loja precisa estar conectado — é ele que cria os grupos e posta. */
    public function ensureConnected(Company $company): string
    {
        $session = $company->whatsapp_session_name;
        $status = $session && $this->waha->isConfigured() ? ($this->waha->getSession($session)['status'] ?? null) : null;

        if ($status !== 'WORKING') {
            throw ValidationException::withMessages(['whatsapp' => 'Conecte o WhatsApp da loja em Integrações antes de criar grupos ou canais de ofertas.']);
        }

        return $session;
    }

    public function create(Company $company, array $data): OfferSeries
    {
        $session = $this->ensureConnected($company);

        $series = new OfferSeries([
            'company_id' => $company->id,
            'kind' => $data['kind'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(5)),
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

        return $group;
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
