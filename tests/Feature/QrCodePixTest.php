<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\QrCodeEvent;
use App\Models\User;
use App\Services\PixBrCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** QR Code de Pix: "Pix copia e cola" estático com a chave da loja (todos os tipos de chave). */
class QrCodePixTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->company = Company::create([
            'name' => 'Ateliê São João', 'slug' => 'loja-pix', 'plan' => Company::PLAN_PRO,
            'catalog_enabled' => true, 'catalog_token' => 'tok-pix', 'whatsapp_pix_key' => 'contato@loja.com',
        ]);
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $this->token = $user->createToken('api')->plainTextToken;
    }

    private function api()
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token);
    }

    private function newPix(array $pix, array $extra = [])
    {
        return $this->api()->postJson('/api/v1/qr-codes', $extra + [
            'name' => 'Pix balcão', 'channel' => 'other', 'destination_type' => 'pix',
            'pix' => $pix + ['name' => 'Ateliê São João', 'city' => 'Niterói'],
        ]);
    }

    public function test_payload_matches_the_central_bank_example(): void
    {
        // Exemplo do manual do BR Code (Banco Central)
        $code = PixBrCode::payload(['key' => '123e4567-e12b-12d1-a456-426655440000', 'name' => 'Fulano de Tal', 'city' => 'BRASILIA']);

        $this->assertSame('00020126580014br.gov.bcb.pix0136123e4567-e12b-12d1-a456-4266554400005204000053039865802BR5913Fulano de Tal6008BRASILIA62070503***63041D3D', $code);
        $this->assertTrue(PixBrCode::isValid($code));
        $this->assertFalse(PixBrCode::isValid(substr($code, 0, -1).'0'));
    }

    public function test_every_key_type_is_checked_and_normalized(): void
    {
        $this->assertSame('52998224725', PixBrCode::normalizeKey('cpf', '529.982.247-25'));
        $this->assertNull(PixBrCode::normalizeKey('cpf', '529.982.247-26'));
        $this->assertSame('11222333000181', PixBrCode::normalizeKey('cnpj', '11.222.333/0001-81'));
        $this->assertNull(PixBrCode::normalizeKey('cnpj', '11.222.333/0001-82'));
        $this->assertSame('loja@x.com', PixBrCode::normalizeKey('email', ' Loja@X.com '));
        $this->assertNull(PixBrCode::normalizeKey('email', 'loja@'));
        $this->assertSame('+5521988887777', PixBrCode::normalizeKey('phone', '(21) 98888-7777'));
        $this->assertSame('+5521988887777', PixBrCode::normalizeKey('phone', '+55 21 98888-7777'));
        $this->assertNull(PixBrCode::normalizeKey('phone', '98888-7777'));
        $this->assertSame('123e4567-e12b-12d1-a456-426655440000', PixBrCode::normalizeKey('random', '123E4567-E12B-12D1-A456-426655440000'));
        $this->assertNull(PixBrCode::normalizeKey('random', '123e4567'));
    }

    public function test_creates_pix_qr_with_amount_and_downloads(): void
    {
        $qr = $this->newPix(['key_type' => 'phone', 'key' => '21 98888-7777', 'amount' => 25.5, 'description' => 'Sinal', 'txid' => 'BALCAO'])
            ->assertCreated()->json();

        $this->assertSame('+5521988887777', $qr['pix']['key']);
        $this->assertSame('Atelie Sao Joao', $qr['pix']['name']);
        $this->assertSame('Niteroi', $qr['pix']['city']);
        $this->assertNull($qr['resolved_url']);
        $code = $qr['pix_code'];
        $this->assertTrue(PixBrCode::isValid($code));
        $this->assertStringContainsString('0114+5521988887777', $code);
        $this->assertStringContainsString('540525.50', $code);
        $this->assertStringContainsString('0506BALCAO', $code);
        $this->assertStringContainsString('0205Sinal', $code);

        // Sugestões no formulário: chave do bot, nome da loja e a cidade do último QR de Pix
        $this->api()->getJson('/api/v1/qr-codes')
            ->assertJsonPath('pix_defaults.key', 'contato@loja.com')
            ->assertJsonPath('pix_defaults.city', 'Niteroi');

        // A imagem leva o código do Pix (não o link curto)
        $svg = $this->api()->get("/api/v1/qr-codes/{$qr['id']}/image?format=svg")->assertOk()->getContent();
        $this->assertStringContainsString('<svg', $svg);
        $this->api()->get("/api/v1/qr-codes/{$qr['id']}/image")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringStartsWith('%PDF', $this->api()->get("/api/v1/qr-codes/{$qr['id']}/print?size=a6")->assertOk()->getContent());

        // O link curto de um QR de Pix não leva a ele nem conta acesso
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone) Mobile'])
            ->getJson("/api/v1/public/qr-codes/go?slug=loja-pix&code={$qr['code']}")
            ->assertOk()->assertJsonPath('url', $this->company->fresh()->catalogUrl());
        $this->assertSame(0, QrCodeEvent::count());
    }

    public function test_invalid_pix_is_refused(): void
    {
        $this->newPix(['key_type' => 'cpf', 'key' => '111.111.111-11'])->assertStatus(422);
        $this->newPix(['key_type' => 'random', 'key' => 'abc'])->assertStatus(422);
        $this->newPix(['key_type' => 'email', 'key' => 'a@b.com', 'amount' => 0])->assertStatus(422);
        $this->newPix(['key_type' => 'email', 'key' => 'a@b.com', 'txid' => 'com espaco'])->assertStatus(422);
        $this->api()->postJson('/api/v1/qr-codes', ['name' => 'Pix', 'channel' => 'other', 'destination_type' => 'pix'])->assertStatus(422);

        // CNPJ válido, sem valor: a pessoa digita o valor no banco
        $qr = $this->newPix(['key_type' => 'cnpj', 'key' => '11.222.333/0001-81'])->assertCreated()->json();
        $this->assertStringNotContainsString('5404', substr($qr['pix_code'], 0, 120));
        $this->assertStringContainsString('62070503***', $qr['pix_code']);
    }
}
