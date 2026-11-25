<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\QrCode;
use App\Models\QrCodeEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Marketing → QR Codes: destino editável, link curto e contagem de leituras. */
class QrCodesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private string $token;

    private const PHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148';

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create([
            'name' => 'Loja', 'slug' => 'loja-qr', 'plan' => Company::PLAN_PRO,
            'catalog_enabled' => true, 'catalog_token' => 'tok-qr', 'catalog_whatsapp' => '21988887777',
        ]);
        $this->token = User::factory()->create(['company_id' => $this->company->id])->createToken('api')->plainTextToken;
    }

    private function api()
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token);
    }

    private function go(string $code, array $extra = [], string $ua = self::PHONE_UA)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['X-Visitor-Agent' => $ua, 'X-Visitor-Ip' => '200.150.10.20'])
            ->getJson('/api/v1/public/qr-codes/go?'.http_build_query(['slug' => 'loja-qr', 'code' => $code] + $extra));
    }

    public function test_qr_for_a_product_redirects_and_counts_scans_links_and_unique_people(): void
    {
        $product = Product::forceCreate(['company_id' => $this->company->id, 'name' => 'Vaso Zen', 'slug' => 'vaso-zen', 'sale_price' => 30, 'active' => true, 'show_in_catalog' => true]);

        $qr = $this->api()->postJson('/api/v1/qr-codes', [
            'name' => 'Panfleto Natal', 'channel' => 'flyer', 'destination_type' => 'product', 'destination_id' => $product->id,
        ])->assertCreated()->assertJsonPath('code', 'panfleto-natal')->json();

        $this->assertStringEndsWith('/qr/loja-qr/panfleto-natal', $qr['link']);
        $this->assertStringEndsWith('/catalog/loja-qr?p=vaso-zen&qr=panfleto-natal', $qr['resolved_url']);

        $this->go('panfleto-natal')->assertOk()->assertJsonPath('url', $qr['resolved_url']);
        $this->go('PANFLETO-NATAL')->assertOk();                 // mesma pessoa de novo
        $this->go('panfleto-natal', ['via' => 'link'])->assertOk(); // abriu pelo link da bio

        $stats = $this->api()->getJson("/api/v1/qr-codes/{$qr['id']}/stats?days=7")->assertOk();
        $stats->assertJsonPath('total', 3)->assertJsonPath('scans', 2)->assertJsonPath('links', 1)
            ->assertJsonPath('unique', 1)->assertJsonPath('devices.mobile', 3);

        $this->api()->getJson('/api/v1/qr-codes')->assertOk()->assertJsonPath('items.0.scans_total', 3);
    }

    public function test_link_preview_robots_are_not_counted(): void
    {
        $this->api()->postJson('/api/v1/qr-codes', ['name' => 'Insta', 'channel' => 'instagram', 'destination_type' => 'catalog'])->assertCreated();

        $this->go('insta', [], 'WhatsApp/2.23.20.0 A')->assertOk();
        $this->assertSame(0, QrCodeEvent::count());
    }

    public function test_destination_changes_without_a_new_qr_and_paused_goes_to_the_catalog(): void
    {
        $qr = $this->api()->postJson('/api/v1/qr-codes', ['name' => 'Banner', 'channel' => 'banner', 'destination_type' => 'catalog'])->json();

        $this->api()->putJson("/api/v1/qr-codes/{$qr['id']}", [
            'name' => 'Banner', 'channel' => 'banner', 'destination_type' => 'whatsapp', 'whatsapp_message' => 'Vi o banner!',
        ])->assertOk()->assertJsonPath('code', 'banner');
        $this->go('banner')->assertJsonPath('url', 'https://wa.me/5521988887777?text='.rawurlencode("Vi o banner!

(QR: banner)"));

        $this->api()->putJson("/api/v1/qr-codes/{$qr['id']}", ['name' => 'Banner', 'channel' => 'banner', 'destination_type' => 'catalog', 'active' => false])->assertOk();
        $this->go('banner')->assertJsonPath('active', false);
        $this->assertStringEndsWith('/catalog/loja-qr', $this->go('banner')->json('url'));
        $this->assertSame(1, QrCodeEvent::count()); // só a leitura de quando estava ativo
    }

    public function test_essential_plan_is_limited_and_other_stores_items_are_refused(): void
    {
        $this->company->update(['plan' => Company::PLAN_ESSENTIAL]);
        for ($i = 1; $i <= QrCode::ESSENTIAL_LIMIT; $i++) {
            $this->api()->postJson('/api/v1/qr-codes', ['name' => "QR {$i}", 'channel' => 'other', 'destination_type' => 'catalog'])->assertCreated();
        }
        $this->api()->postJson('/api/v1/qr-codes', ['name' => 'Mais um', 'channel' => 'other', 'destination_type' => 'catalog'])->assertStatus(403);

        $other = Company::create(['name' => 'Outra', 'slug' => 'outra-qr', 'plan' => Company::PLAN_PRO]);
        $foreign = Product::forceCreate(['company_id' => $other->id, 'name' => 'Alheio', 'slug' => 'alheio', 'sale_price' => 1]);
        $this->company->update(['plan' => Company::PLAN_PRO]);
        $this->api()->postJson('/api/v1/qr-codes', ['name' => 'X', 'channel' => 'other', 'destination_type' => 'product', 'destination_id' => $foreign->id])
            ->assertStatus(422);
    }

    public function test_image_comes_as_png_and_svg(): void
    {
        $id = $this->api()->postJson('/api/v1/qr-codes', ['name' => 'Cartão', 'channel' => 'business_card', 'destination_type' => 'catalog'])->json('id');

        $this->api()->get("/api/v1/qr-codes/{$id}/image")->assertOk()->assertHeader('Content-Type', 'image/png');
        $svg = $this->api()->get("/api/v1/qr-codes/{$id}/image?format=svg")->assertOk();
        $this->assertStringContainsString('<svg', $svg->getContent());
    }
}
