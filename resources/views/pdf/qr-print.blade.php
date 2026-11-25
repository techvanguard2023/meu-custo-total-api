{{-- Arte pronta para imprimir do QR Code (A6 ou A5): logo, nome da loja, a chamada, o QR e o link curto. --}}
@php
    $big = $size === 'a5';
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 0; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: DejaVu Sans, sans-serif; color: #111827; }
    .page { padding: {{ $big ? '34px 40px' : '30px 22px' }}; text-align: center; }
    .bar { height: {{ $big ? '10px' : '7px' }}; background: {{ $accent }}; }
    .logo { max-width: {{ $big ? '170px' : '120px' }}; max-height: {{ $big ? '100px' : '70px' }}; margin: 0 auto {{ $big ? '14px' : '10px' }}; display: block; }
    .store { font-size: {{ $big ? '22px' : '15px' }}; font-weight: bold; margin: 0 0 {{ $big ? '18px' : '10px' }}; }
    .headline { font-size: {{ $big ? '26px' : '18px' }}; font-weight: bold; line-height: 1.25; color: {{ $accent }}; margin: 0 0 {{ $big ? '22px' : '14px' }}; }
    .qr { width: {{ $big ? '310px' : '230px' }}; height: {{ $big ? '310px' : '230px' }}; }
    .coupon { display: inline-block; margin-top: {{ $big ? '22px' : '14px' }}; padding: {{ $big ? '10px 20px' : '6px 14px' }}; border: 2px dashed {{ $accent }}; border-radius: 8px; font-size: {{ $big ? '15px' : '11px' }}; }
    .coupon strong { letter-spacing: 1px; }
    .link { margin-top: {{ $big ? '20px' : '12px' }}; font-size: {{ $big ? '14px' : '10px' }}; color: #6b7280; }
</style>
</head>
<body>
    <div class="bar"></div>
    <div class="page">
        @if ($logo)
            <img class="logo" src="{{ $logo }}" alt="">
        @endif
        <p class="store">{{ $storeName }}</p>
        <p class="headline">{{ $headline }}</p>
        <img class="qr" src="{{ $qr }}" alt="QR Code">
        @if ($coupon)
            <div class="coupon">Cupom já aplicado: <strong>{{ $coupon }}</strong></div>
        @endif
        <p class="link">{{ $link }}</p>
    </div>
</body>
</html>
