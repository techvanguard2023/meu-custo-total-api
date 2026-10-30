<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\TeamInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/** Equipe da empresa (só o dono chega aqui — ver TeamAccess): convidar, trocar o perfil e remover. */
class TeamController extends Controller
{
    public function index(Request $request)
    {
        $company = $request->user()->company;

        return response()->json([
            'limit' => $company->userLimit(),
            'plan' => $company->plan,
            'members' => $company->users()->orderBy('id')->get()->map(fn (User $user) => $this->member($user))->values(),
        ]);
    }

    /** Convida uma pessoa: a conta nasce sem senha conhecida e o link do e-mail leva à criação da senha. */
    public function store(Request $request)
    {
        $company = $request->user()->company;

        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(User::ROLES)],
        ], [
            'email.unique' => 'Este e-mail já tem conta no Meu Custo Total.',
        ]);

        $limit = $company->userLimit();
        abort_if(
            $company->users()->count() >= $limit,
            403,
            $limit === 1
                ? 'O plano Gratuito é só para uma pessoa. Assine o Essencial (2 pessoas) ou o Pro (5 pessoas) para montar sua equipe.'
                : "Seu plano comporta {$limit} pessoas, contando com você. ".($company->isPro() ? 'Remova alguém para convidar outra pessoa.' : 'Assine o Pro para ter até 5.')
        );

        $user = new User;
        $user->forceFill([
            'company_id' => $company->id,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make(Str::random(40)),
            'role' => $data['role'],
            'invited_at' => now(),
        ])->save();

        return response()->json([
            'member' => $this->member($user),
            ...$this->sendInvite($user, $request->user()),
        ], 201);
    }

    public function update(Request $request, User $user)
    {
        $this->authorizeMember($request, $user);

        $data = $request->validate(['role' => ['required', Rule::in(User::ROLES)]]);

        abort_if(
            $user->isOwner() && $data['role'] !== User::ROLE_OWNER && $this->ownersCount($request) <= 1,
            422,
            'A empresa precisa de pelo menos um dono.'
        );

        $user->forceFill(['role' => $data['role']])->save();

        return response()->json(['member' => $this->member($user)]);
    }

    /** Remove a pessoa da equipe: a conta e os acessos dela deixam de existir. */
    public function destroy(Request $request, User $user)
    {
        $this->authorizeMember($request, $user);

        abort_if($user->id === $request->user()->id, 422, 'Você não pode remover a si mesmo.');
        abort_if($user->isOwner() && $this->ownersCount($request) <= 1, 422, 'A empresa precisa de pelo menos um dono.');

        $user->tokens()->delete();
        Password::broker('invites')->deleteToken($user);
        $user->delete();

        return response()->json(['message' => 'Pessoa removida da equipe.']);
    }

    /** Manda o convite de novo (link novo; o anterior deixa de valer). */
    public function resend(Request $request, User $user)
    {
        $this->authorizeMember($request, $user);
        abort_if($user->invitation_accepted_at !== null || $user->invited_at === null, 422, 'Esta pessoa já aceitou o convite.');

        $user->forceFill(['invited_at' => now()])->save();

        return response()->json([
            'member' => $this->member($user),
            ...$this->sendInvite($user, $request->user()),
        ]);
    }

    /**
     * Gera o link e manda por e-mail. O link também volta pra tela: se o e-mail não chegar, o dono
     * copia e manda pelo WhatsApp.
     */
    private function sendInvite(User $user, User $invitedBy): array
    {
        $token = Password::broker('invites')->createToken($user);
        $url = rtrim(config('services.frontend_url'), '/').'/convite?token='.$token.'&email='.urlencode($user->email);

        try {
            $user->notify(new TeamInvitation($token, $invitedBy));
            $emailSent = true;
        } catch (Throwable $e) {
            Log::warning("Convite da equipe não enviado por e-mail ({$user->email}): {$e->getMessage()}");
            $emailSent = false;
        }

        return ['invite_url' => $url, 'email_sent' => $emailSent];
    }

    private function member(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'pending' => $user->invited_at !== null && $user->invitation_accepted_at === null,
            'invited_at' => $user->invited_at?->toIso8601String(),
            'blocked_by_plan' => $user->isBlockedByPlanLimit(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    private function authorizeMember(Request $request, User $user): void
    {
        abort_unless($user->company_id === $request->user()->company_id, 404);
    }

    private function ownersCount(Request $request): int
    {
        return $request->user()->company->users()->where('role', User::ROLE_OWNER)->count();
    }
}
