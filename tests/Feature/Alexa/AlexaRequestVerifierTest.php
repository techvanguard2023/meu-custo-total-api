<?php

namespace Tests\Feature\Alexa;

use App\Services\Alexa\AlexaRequestVerifier;
use Tests\TestCase;

class AlexaRequestVerifierTest extends TestCase
{
    private const SKILL = 'amzn1.ask.skill.teste';

    private const CERT_URL = 'https://s3.amazonaws.com/echo.api/echo-api-cert.pem';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/alexa-test-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_accepts_amazon_cert_urls(): void
    {
        foreach ([
            'https://s3.amazonaws.com/echo.api/echo-api-cert.pem',
            'https://s3.amazonaws.com:443/echo.api/echo-api-cert.pem',
            'https://s3.amazonaws.com/echo.api/../echo.api/echo-api-cert.pem',
            'HTTPS://s3.amazonaws.com/echo.api/echo-api-cert.pem',
            'https://S3.AMAZONAWS.COM/echo.api/echo-api-cert.pem',
        ] as $url) {
            $this->assertTrue(AlexaRequestVerifier::validCertUrl($url), $url);
        }
    }

    public function test_rejects_other_cert_urls(): void
    {
        foreach ([
            'http://s3.amazonaws.com/echo.api/echo-api-cert.pem',
            'https://notamazon.com/echo.api/echo-api-cert.pem',
            'https://s3.amazonaws.com/EcHo.aPi/echo-api-cert.pem',
            'https://s3.amazonaws.com/invalid.path/echo-api-cert.pem',
            'https://s3.amazonaws.com:563/echo.api/echo-api-cert.pem',
            'https://s3.amazonaws.com/echo.api/../other/echo-api-cert.pem',
        ] as $url) {
            $this->assertFalse(AlexaRequestVerifier::validCertUrl($url), $url);
        }
    }

    public function test_valid_signed_request_passes(): void
    {
        [$pem, $key] = $this->certificate('echo-api.amazon.com');
        $body = $this->body();

        $this->assertNull($this->verifier($pem)->failure($body, self::CERT_URL, $this->sign($body, $key)));
    }

    public function test_tampered_body_fails(): void
    {
        [$pem, $key] = $this->certificate('echo-api.amazon.com');
        $signature = $this->sign($this->body(), $key);

        $this->assertSame('assinatura inválida', $this->verifier($pem)->failure($this->body(intent: 'Outra'), self::CERT_URL, $signature));
    }

    public function test_certificate_for_other_domain_fails(): void
    {
        [$pem, $key] = $this->certificate('example.com');
        $body = $this->body();

        $this->assertSame('certificado não é da Alexa', $this->verifier($pem)->failure($body, self::CERT_URL, $this->sign($body, $key)));
    }

    public function test_untrusted_chain_fails(): void
    {
        [$pem, $key] = $this->certificate('echo-api.amazon.com');
        [$otherCa] = $this->certificate('echo-api.amazon.com');
        $body = $this->body();
        $verifier = new AlexaRequestVerifier(self::SKILL, fn () => $pem, $this->write('other-ca.pem', $otherCa));

        $this->assertSame('cadeia do certificado não confiável', $verifier->failure($body, self::CERT_URL, $this->sign($body, $key)));
    }

    public function test_other_skill_fails(): void
    {
        [$pem, $key] = $this->certificate('echo-api.amazon.com');
        $body = $this->body(skill: 'amzn1.ask.skill.outra');

        $this->assertSame('skill diferente', $this->verifier($pem)->failure($body, self::CERT_URL, $this->sign($body, $key)));
    }

    public function test_old_request_fails(): void
    {
        [$pem, $key] = $this->certificate('echo-api.amazon.com');
        $body = $this->body(timestamp: now()->subSeconds(200)->toIso8601ZuluString());

        $this->assertSame('requisição fora do horário', $this->verifier($pem)->failure($body, self::CERT_URL, $this->sign($body, $key)));
    }

    public function test_missing_signature_or_bad_url_fails(): void
    {
        [$pem] = $this->certificate('echo-api.amazon.com');
        $body = $this->body();

        $this->assertSame('sem assinatura', $this->verifier($pem)->failure($body, self::CERT_URL, null));
        $this->assertSame('endereço do certificado inválido', $this->verifier($pem)->failure($body, 'https://evil.com/echo.api/c.pem', 'x'));
    }

    private function verifier(string $pem): AlexaRequestVerifier
    {
        return new AlexaRequestVerifier(self::SKILL, fn () => $pem, $this->write('ca.pem', $pem));
    }

    private function body(string $skill = self::SKILL, ?string $timestamp = null, string $intent = 'ProximaEntregaIntent'): string
    {
        return json_encode([
            'version' => '1.0',
            'context' => ['System' => ['application' => ['applicationId' => $skill]]],
            'request' => ['type' => 'IntentRequest', 'timestamp' => $timestamp ?? now()->toIso8601ZuluString(), 'intent' => ['name' => $intent]],
        ]);
    }

    private function sign(string $body, \OpenSSLAsymmetricKey $key): string
    {
        openssl_sign($body, $signature, $key, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    /** Certificado autoassinado (vira a própria "autoridade" nos testes) com o SAN pedido. */
    private function certificate(string $san): array
    {
        $config = $this->write('openssl-'.uniqid().'.cnf', implode("\n", [
            '[req]', 'distinguished_name = dn', '[dn]',
            '[ext]', "subjectAltName = DNS:{$san}", 'basicConstraints = critical,CA:TRUE', 'keyUsage = digitalSignature,keyCertSign',
        ]));
        $options = ['config' => $config, 'x509_extensions' => 'ext', 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

        $key = openssl_pkey_new($options);
        $csr = openssl_csr_new(['commonName' => $san], $key, $options);
        $cert = openssl_csr_sign($csr, null, $key, 1, $options, random_int(1, PHP_INT_MAX));
        openssl_x509_export($cert, $pem);

        return [$pem, $key];
    }

    private function write(string $name, string $content): string
    {
        file_put_contents($path = $this->dir.'/'.$name, $content);

        return $path;
    }
}
