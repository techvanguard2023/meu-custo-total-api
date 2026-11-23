<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Domínios próprios ao sair do Pro: guardados e parados; voltam com o Pro; liberados depois de 30 dias fora. */
class DomainSuspensionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.vercel.token' => 'vercel-token', 'services.vercel.project_id' => 'prj_1']);
        Http::fake(['api.vercel.com/*' => Http::response([], 200)]);
        $this->company = Company::create(['name' => 'Alma Forma', 'slug' => 'alma-forma', 'plan' => Company::PLAN_PRO]);
        $this->company->forceFill([
            'catalog_enabled' => true, 'catalog_token' => 'tok-catalogo',
            'custom_domain' => 'loja.almaforma.com.br', 'custom_domain_verified_at' => now()->subMonth(),
            'partner_domain' => 'parceiro.almaforma.com.br', 'partner_domain_verified_at' => now()->subMonth(),
        ])->save();
    }

    public function test_suspended_subscription_keeps_the_domains_and_they_come_back_with_pro(): void
    {
        $this->company->update(['plan' => Company::PLAN_FREE]);

        $company = $this->company->fresh();
        $this->assertSame('loja.almaforma.com.br', $company->custom_domain);
        $this->assertSame('parceiro.almaforma.com.br', $company->partner_domain);
        $this->assertNotNull($company->domains_suspended_at);
        $this->assertFalse($company->hasActiveCustomDomain());
        $this->getJson('/api/v1/public/domain/loja.almaforma.com.br')->assertNotFound();
        Http::assertNothingSent();

        // Troca entre planos sem Pro mantém a data de quando saiu do Pro
        $this->travel(5)->days();
        $since = $company->domains_suspended_at->toIso8601String();
        $company->update(['plan' => Company::PLAN_ESSENTIAL]);
        $this->assertSame($since, $company->fresh()->domains_suspended_at->toIso8601String());

        $company->update(['plan' => Company::PLAN_PRO]);
        $company = $company->fresh();
        $this->assertNull($company->domains_suspended_at);
        $this->assertTrue($company->hasActiveCustomDomain());
        $this->getJson('/api/v1/public/domain/loja.almaforma.com.br')->assertOk()->assertJsonPath('slug', 'alma-forma');
        Http::assertNothingSent();
    }

    public function test_domains_are_released_after_30_days_out_of_pro(): void
    {
        $this->company->update(['plan' => Company::PLAN_FREE]);
        $proStore = Company::create(['name' => 'Outra', 'slug' => 'outra', 'plan' => Company::PLAN_PRO]);
        $proStore->forceFill(['custom_domain' => 'loja.outra.com.br', 'domains_suspended_at' => now()->subYear()])->save();

        $this->travel(29)->days();
        $this->artisan('domains:release-suspended')->assertSuccessful();
        $this->assertSame('loja.almaforma.com.br', $this->company->fresh()->custom_domain);
        Http::assertNothingSent();

        $this->travel(2)->days();
        $this->artisan('domains:release-suspended')->assertSuccessful();
        $company = $this->company->fresh();
        $this->assertNull($company->custom_domain);
        $this->assertNull($company->partner_domain);
        $this->assertNull($company->custom_domain_verified_at);
        $this->assertNull($company->domains_suspended_at);
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), '/domains/loja.almaforma.com.br'));
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), '/domains/parceiro.almaforma.com.br'));

        // Loja no Pro não perde o domínio
        $this->assertSame('loja.outra.com.br', $proStore->fresh()->custom_domain);
    }

    public function test_store_without_domains_is_not_marked(): void
    {
        $plain = Company::create(['name' => 'Sem domínio', 'slug' => 'sem-dominio', 'plan' => Company::PLAN_PRO]);
        $plain->update(['plan' => Company::PLAN_FREE]);
        $this->assertNull($plain->fresh()->domains_suspended_at);
    }
}
