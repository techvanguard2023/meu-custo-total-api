<?php

namespace Tests\Feature\Alexa;

use App\Services\Alexa\AlexaSpeech;
use App\Support\Delivery;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AlexaSpeechTest extends TestCase
{
    private AlexaSpeech $speech;

    protected function setUp(): void
    {
        parent::setUp();
        // Sexta-feira, 13/11/2026, 9h em Brasília
        $this->travelTo(Carbon::parse('2026-11-13 09:00', Delivery::TZ));
        $this->speech = new AlexaSpeech;
    }

    public function test_no_deliveries_today(): void
    {
        $today = now(Delivery::TZ)->startOfDay();

        $this->assertSame('Você não tem entregas para hoje.', $this->speech->period($today, $today, [])['speech']);
    }

    public function test_lists_today_with_time_customer_and_method(): void
    {
        $today = now(Delivery::TZ)->startOfDay();
        $result = $this->speech->period($today, $today, [
            $this->row(time: '10:00', items: '2x Chaveiro Rosa', customer: 'Maria Silva', method: 'pickup'),
            $this->row(time: '14:30', items: '1x Bandeja Japandi (Branco)', customer: 'João', method: 'local'),
            $this->row(items: '1x Vaso'),
        ], overdueCount: 1);

        $this->assertSame(
            'Hoje, você tem 3 entregas. Às 10 horas, 2 Chaveiro Rosa para Maria, retirada. Às 14 e 30, 1 Bandeja Japandi (Branco) para João, entrega. 1 Vaso. Atenção: 1 entrega atrasada.',
            $result['speech']
        );
        $this->assertStringContainsString('Maria Silva', $result['card']);
        $this->assertStringNotContainsString('R$', $result['speech']);
    }

    public function test_more_than_five_only_speaks_five(): void
    {
        $today = now(Delivery::TZ)->startOfDay();
        $rows = array_map(fn ($i) => $this->row(items: "1x Item {$i}"), range(1, 7));

        $speech = $this->speech->period($today, $today, $rows)['speech'];

        $this->assertStringContainsString('7 entregas', $speech);
        $this->assertStringContainsString('Item 5', $speech);
        $this->assertStringNotContainsString('Item 6', $speech);
        $this->assertStringContainsString('E mais 2 entregas', $speech);
    }

    public function test_many_items_are_summarized(): void
    {
        $today = now(Delivery::TZ)->startOfDay();
        $speech = $this->speech->period($today, $today, [$this->row(items: '1x A, 2x B, 3x C, 4x D')])['speech'];

        $this->assertStringContainsString('1 A, 2 B e mais 2 itens', $speech);
    }

    public function test_tomorrow_and_other_day_labels(): void
    {
        $tomorrow = now(Delivery::TZ)->addDay()->startOfDay();
        $this->assertSame('Você não tem entregas para amanhã.', $this->speech->period($tomorrow, $tomorrow, [])['speech']);

        $tuesday = Carbon::parse('2026-11-17', Delivery::TZ);
        $speech = $this->speech->period($tuesday, $tuesday, [$this->row(date: '2026-11-17', items: '1x Vaso')])['speech'];
        $this->assertStringStartsWith('Na terça-feira, 17 de novembro, você tem 1 entrega.', $speech);
    }

    public function test_week_mentions_each_day(): void
    {
        $monday = Carbon::parse('2026-11-09', Delivery::TZ);
        $speech = $this->speech->period($monday, $monday->copy()->addDays(6), [
            $this->row(date: '2026-11-13', time: '15:00', items: '1x Vaso'),
            $this->row(date: '2026-11-14', items: '1x Caneca'),
        ])['speech'];

        $this->assertSame('Nesta semana, você tem 2 entregas. Hoje, às 15 horas, 1 Vaso. Amanhã, 1 Caneca.', $speech);
    }

    public function test_next_and_overdue(): void
    {
        $this->assertSame('Você não tem nenhuma entrega marcada daqui pra frente.', $this->speech->next(null)['speech']);
        $this->assertSame(
            'Sua próxima entrega é hoje, à 1 e 05: 1 Vaso para Ana.',
            $this->speech->next($this->row(time: '01:05', items: '1x Vaso', customer: 'Ana'))['speech']
        );
        $this->assertSame('Nenhuma entrega atrasada. Tudo em dia!', $this->speech->overdue([])['speech']);
        $this->assertSame(
            'Você tem 1 entrega atrasada. Ontem, 1 Vaso.',
            $this->speech->overdue([$this->row(date: '2026-11-12', items: '1x Vaso')])['speech']
        );
    }

    private function row(string $date = '2026-11-13', ?string $time = null, string $items = '1x Item', ?string $customer = null, ?string $method = null): array
    {
        return compact('date', 'time', 'items', 'customer', 'method');
    }
}
