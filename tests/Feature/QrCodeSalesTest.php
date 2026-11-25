<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\QrCode;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** QR Codes, fase 2: cupom no QR, carrinho e pedido ligados ao QR, cor/logo e a arte para imprimir. */
class QrCodeSalesTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->company = Company::create([
            'name' => 'Loja', 'slug' => 'loja-qrv', 'plan' => Company::PLAN_PRO,
            'catalog_enabled' => true, 'catalog_token' => 'tok-qrv', 'catalog_whatsapp' => '21988887777',
        ]);
        $this->user = User::factory()->create(['company_id' => $this->company->id]);
        $this->token = $this->user->createToken('api')->plainTextToken;
    }

    private function api(?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token ?? $this->token);
    }

    private function newQr(array $extra = []): array
    {
        return $this->api()->postJson('/api/v1/qr-codes', $extra + ['name' => 'Panfleto', 'channel' => 'flyer', 'destination_type' => 'catalog'])
            ->assertCreated()->json();
    }

    public function test_coupon_goes_applied_in_the_catalog_link(): void
    {
        $coupon = Coupon::create(['company_id' => $this->company->id, 'code' => 'FEIRA10', 'type' => 'percent', 'value' => 10, 'active' => true]);

        $qr = $this->newQr(['coupon_id' => $coupon->id]);
        $this->assertStringEndsWith('/catalog/loja-qrv?qr=panfleto&cupom=FEIRA10', $qr['resolved_url']);

        // Cupom desligado: o link continua, sem o cupom
        $coupon->update(['active' => false]);
        $this->assertStringEndsWith('/catalog/loja-qrv?qr=panfleto', $this->api()->getJson('/api/v1/qr-codes')->json('items.0.resolved_url'));

        // Cupom não vale para WhatsApp
        $this->api()->postJson('/api/v1/qr-codes', ['name' => 'Zap', 'channel' => 'other', 'destination_type' => 'whatsapp', 'coupon_id' => $coupon->id])
            ->assertStatus(422);
    }

    public function test_cart_sent_and_bot_order_count_as_sales_of_the_qr(): void
    {
        $qr = $this->newQr();
        $productId = $this->api()->postJson('/api/v1/products', ['name' => 'Chaveiro', 'cost' => 5, 'sale_price' => 25, 'stock_quantity' => 10])
            ->assertCreated()->json('id');

        // Carrinho enviado pelo WhatsApp: reenviar no mesmo dia só atualiza o valor
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone) Mobile'])->postJson('/api/v1/public/catalog/loja-qrv/qr-cart', ['code' => 'panfleto', 'total' => 40])->assertNoContent();
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone) Mobile'])->postJson('/api/v1/public/catalog/loja-qrv/qr-cart', ['code' => 'panfleto', 'total' => 50])->assertNoContent();

        // Bot cria o pedido com a linha "QR: panfleto" da mensagem
        $bot = $this->user->createToken('whatsapp-bot')->plainTextToken;
        $this->api($bot)->postJson('/api/v1/quotes/external-order', [
            'customer_name' => 'Ana', 'customer_phone' => '5521999990000', 'external_reference' => 'msg-qr',
            'products' => [['product_id' => $productId, 'quantity' => 2]], 'origin_qr_code' => 'panfleto',
        ])->assertCreated();
        $this->assertSame($qr['id'], Quote::where('external_reference', 'msg-qr')->value('origin_qr_code_id'));

        // A IA mandou no campo do expositor: sem expositor com esse código, vale como QR
        $this->api($bot)->postJson('/api/v1/quotes/external-order', [
            'customer_name' => 'Bia', 'customer_phone' => '5521999990001', 'external_reference' => 'msg-qr2',
            'products' => [['product_id' => $productId, 'quantity' => 1]], 'origin_display_code' => 'QR: panfleto',
        ])->assertCreated();
        $this->assertSame($qr['id'], Quote::where('external_reference', 'msg-qr2')->value('origin_qr_code_id'));

        Quote::where('origin_qr_code_id', $qr['id'])->update(['status' => Quote::STATUS_APPROVED]);

        $stats = $this->api()->getJson("/api/v1/qr-codes/{$qr['id']}/stats")->assertOk();
        $stats->assertJsonPath('carts', 1)->assertJsonPath('total', 0)->assertJsonPath('orders', 2);
        $this->assertEquals(50, $stats->json('carts_total'));
        $this->assertEquals(75, $stats->json('revenue'));
        $this->api()->getJson('/api/v1/qr-codes')->assertJsonPath('items.0.orders_30', 2);
    }

    public function test_color_must_be_dark_and_logo_needs_a_store_logo(): void
    {
        $this->api()->postJson('/api/v1/qr-codes', ['name' => 'Claro', 'channel' => 'other', 'destination_type' => 'catalog', 'color' => '#ffee00'])
            ->assertStatus(422);
        $this->api()->postJson('/api/v1/qr-codes', ['name' => 'Logo', 'channel' => 'other', 'destination_type' => 'catalog', 'with_logo' => true])
            ->assertStatus(422);

        // Logo da loja cadastrada: QR com cor e logo sai em PNG, SVG e na arte para imprimir
        $logo = imagecreatetruecolor(60, 30);
        ob_start();
        imagepng($logo);
        Storage::disk('public')->put('logos/loja.png', ob_get_clean());
        $this->company->update(['logo_path' => 'logos/loja.png']);

        $qr = $this->newQr(['name' => 'Bonito', 'color' => '#1E3A8A', 'with_logo' => true, 'headline' => 'Veja as novidades']);
        $this->assertSame('#1e3a8a', $qr['color']);

        $this->api()->get("/api/v1/qr-codes/{$qr['id']}/image")->assertOk()->assertHeader('Content-Type', 'image/png');
        $svg = $this->api()->get("/api/v1/qr-codes/{$qr['id']}/image?format=svg")->assertOk()->getContent();
        $this->assertStringContainsString('fill="#1e3a8a"', $svg);
        $this->assertStringContainsString('<image href="data:image/png;base64,', $svg);

        $pdf = $this->api()->get("/api/v1/qr-codes/{$qr['id']}/print?size=a5")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }
}
