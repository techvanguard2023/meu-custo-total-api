<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AgendaController;
use App\Http\Controllers\Api\DeliveryDatesController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerPortalController;
use App\Http\Controllers\Api\PartnerPortalController;
use App\Http\Controllers\Api\DisplayQrController;
use App\Http\Controllers\Api\IntegrationTokenController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\MercadoPagoController;
use App\Http\Controllers\Api\MercadoPagoWebhookController;
use App\Http\Controllers\Api\PixChargeController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\CouponAutomationController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\OfferController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\WhatsAppConnectionController;
use App\Http\Controllers\Api\WhatsAppHandoffController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\MaterialCategoryController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\PlatformFeedbackController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\TestimonialController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProductReviewController;
use App\Http\Controllers\Api\PublicCatalogController;
use App\Http\Controllers\Api\PublicReviewController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\PrinterController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\ProductCollectionController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\QuoteChargeController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\QuoteRequestController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SalesChannelController;
use App\Http\Controllers\Api\DisplayController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Middleware\EnforceTeamAccess;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\TrackLastSeen;
use App\Http\Middleware\LogImpersonation;
use App\Http\Middleware\RestrictIntegrationTokens;
use App\Http\Controllers\Admin\AnnouncementController as AdminAnnouncementController;
use App\Http\Controllers\Admin\SystemController as AdminSystemController;
use App\Http\Controllers\Admin\CompanyController as AdminCompanyController;
use App\Http\Controllers\Admin\FinanceController as AdminFinanceController;
use App\Http\Controllers\Admin\OverviewController as AdminOverviewController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\SupportController as AdminSupportController;
use App\Http\Controllers\Admin\FeedbackController as AdminFeedbackController;
use App\Http\Controllers\Admin\TestimonialController as AdminTestimonialController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\AffiliateOfferController;
use App\Http\Controllers\Api\AffiliateIntegrationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::get('status', function () {
        return response()->json(['status' => 'API V1 Meu Custo Total is alive!'], 200);
    });

    // Limites em AppServiceProvider (força bruta e cadastro em massa)
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:password-forgot');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset');

    // Convite da equipe: a pessoa convidada confere o convite e cria a senha
    Route::get('/invitation', [InvitationController::class, 'show'])->middleware('throttle:30,1');
    Route::post('/invitation/accept', [InvitationController::class, 'accept'])->middleware('throttle:10,1');

    // Catálogo público — sem autenticação, acessado por clientes finais via link compartilhado.
    Route::get('/public/catalog/{token}', [PublicCatalogController::class, 'show'])
        ->middleware('throttle:60,1');

    // "Meus pedidos" do catálogo: login sem senha com código pelo WhatsApp da loja (ver CustomerPortal)
    Route::post('/public/catalog/{token}/account/code', [CustomerPortalController::class, 'requestCode'])->middleware('throttle:5,1');
    Route::post('/public/catalog/{token}/account/verify', [CustomerPortalController::class, 'verifyCode'])->middleware('throttle:15,1');
    Route::get('/public/catalog/{token}/account/orders', [CustomerPortalController::class, 'orders'])->middleware('throttle:60,1');
    Route::post('/public/catalog/{token}/account/logout', [CustomerPortalController::class, 'logout'])->middleware('throttle:30,1');
    Route::get('/public/catalog/{token}/account/cashback', [CustomerPortalController::class, 'cashback'])->middleware('throttle:60,1');
    // Pagar o pedido pelo Mercado Pago (Pix ou link do cartão) e acompanhar o pagamento
    Route::post('/public/catalog/{token}/account/orders/{key}/pay', [CustomerPortalController::class, 'payOrder'])->middleware('throttle:10,1');
    Route::get('/public/catalog/{token}/account/charges/{charge}', [CustomerPortalController::class, 'charge'])->where('charge', '[0-9]+')->middleware('throttle:60,1');
    Route::get('/public/catalog/{token}/referral/{code}', [CustomerPortalController::class, 'referral'])->middleware('throttle:30,1');

    // QR Code da placa do expositor: registra o escaneamento e diz pra onde levar o cliente
    Route::get('/public/qr/{slug}/{code}', [DisplayQrController::class, 'show'])->middleware('throttle:60,1');
    Route::post('/public/qr/{slug}/{code}/click', [DisplayQrController::class, 'click'])->middleware('throttle:60,1');

    // Área do parceiro (loja com expositor): mesmo login por código no WhatsApp, escopo próprio
    Route::post('/public/partner/{slug}/code', [PartnerPortalController::class, 'requestCode'])->middleware('throttle:5,1');
    Route::post('/public/partner/{slug}/verify', [PartnerPortalController::class, 'verifyCode'])->middleware('throttle:15,1');
    Route::get('/public/partner/{slug}', [PartnerPortalController::class, 'show'])->middleware('throttle:60,1');
    Route::post('/public/partner/{slug}/displays/{display}/sales', [PartnerPortalController::class, 'storeSale'])->middleware('throttle:30,1');
    Route::post('/public/partner/{slug}/sales/{quote}/undo', [PartnerPortalController::class, 'undoSale'])->middleware('throttle:30,1');
    Route::post('/public/partner/{slug}/logout', [PartnerPortalController::class, 'logout'])->middleware('throttle:30,1');

    // Domínio próprio: o frontend, aberto num domínio de loja, descobre qual catálogo mostrar
    Route::get('/public/domain/{host}', [PublicCatalogController::class, 'byDomain'])
        ->middleware('throttle:120,1');

    // Avaliação do cliente final — sem autenticação, só com o link recebido.
    // O POST tem limite mais apertado que a leitura: é escrita vinda de fora.
    // Link fixo dos grupos/canal de ofertas: leva ao grupo com vaga
    Route::get('/public/offers/{slug}', [OfferController::class, 'publicShow'])->middleware('throttle:60,1');
    // Catálogo aberto pelo link de uma oferta (?o=CODIGO): conta o clique
    Route::post('/public/offer-clicks/{code}', [OfferController::class, 'publicClick'])->middleware('throttle:60,1');
    // Link rastreado do achadinho (…/r/CODIGO): conta o clique e devolve o link de afiliado
    Route::get('/public/r/{code}', [AffiliateOfferController::class, 'publicRedirect'])->middleware('throttle:60,1');
    // Cupom no carrinho do catálogo (limite baixo: ninguém fica chutando códigos)
    Route::post('/public/catalog/{token}/coupon', [CouponController::class, 'publicCheck'])->middleware('throttle:30,1');

    // Opinião sobre a plataforma pelo link do e-mail (fim do teste grátis), sem login
    Route::get('/public/feedback/{token}', [PlatformFeedbackController::class, 'publicShow'])->middleware('throttle:30,1');
    Route::post('/public/feedback/{token}', [PlatformFeedbackController::class, 'publicStore'])->middleware('throttle:10,1');
    // Carrinho do catálogo: próximas datas de entrega livres (quando a loja deixa o cliente escolher)
    Route::get('/public/catalog/{token}/delivery-dates', [DeliveryDatesController::class, 'publicDates'])->middleware('throttle:60,1');
    // Depoimentos aprovados (seção da landing)
    Route::get('/public/testimonials', [TestimonialController::class, 'publicIndex'])->middleware('throttle:60,1');

    // Pagamento online: volta do "Conectar com Mercado Pago" (navegador do lojista) e avisos do Mercado Pago
    Route::get('/mercadopago/callback', [MercadoPagoController::class, 'callback'])->middleware('throttle:30,1');
    Route::post('/webhooks/mercadopago', MercadoPagoWebhookController::class)->middleware('throttle:300,1');

    Route::get('/public/review/{token}', [PublicReviewController::class, 'show'])
        ->middleware('throttle:60,1');
    Route::post('/public/review/{token}', [PublicReviewController::class, 'store'])
        ->middleware('throttle:10,1');

    // EnforceTeamAccess: o que cada perfil da equipe acessa e vê (App\Services\TeamAccess)
    Route::middleware(['auth:sanctum', RestrictIntegrationTokens::class, EnforceTeamAccess::class, TrackLastSeen::class, LogImpersonation::class])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        // Sair do "Entrar como" (área admin) e comunicados da plataforma (faixa no app)
        Route::post('/impersonation/stop', [AuthController::class, 'stopImpersonation']);
        Route::get('/announcements', [AdminAnnouncementController::class, 'active']);
        Route::post('/announcements/{announcement}/seen', [AdminAnnouncementController::class, 'seen'])->whereNumber('announcement');

        // Área admin da plataforma (só PLATFORM_ADMIN_EMAILS; para os outros, 404). Toda ação vai pra auditoria.
        Route::prefix('admin')->middleware(EnsurePlatformAdmin::class)->group(function () {
            Route::get('/overview', AdminOverviewController::class);
            Route::get('/companies', [AdminCompanyController::class, 'index']);
            Route::get('/companies/{company}', [AdminCompanyController::class, 'show']);
            Route::post('/companies/{company}/comp', [AdminCompanyController::class, 'setComp']);
            Route::delete('/companies/{company}/comp', [AdminCompanyController::class, 'removeComp']);
            Route::post('/companies/{company}/discount', [AdminCompanyController::class, 'setDiscount'])->middleware('throttle:20,1');
            Route::delete('/companies/{company}/discount', [AdminCompanyController::class, 'removeDiscount']);
            Route::post('/companies/{company}/block', [AdminCompanyController::class, 'block']);
            Route::delete('/companies/{company}/block', [AdminCompanyController::class, 'unblock']);
            Route::put('/companies/{company}/notes', [AdminCompanyController::class, 'notes']);
            Route::post('/companies/{company}/deletion', [AdminCompanyController::class, 'deleteCompany'])->middleware('throttle:10,1');
            Route::delete('/companies/{company}/deletion', [AdminCompanyController::class, 'cancelDeletion']);
            Route::post('/companies/{company}/whatsapp/restart', [AdminCompanyController::class, 'restartWhatsapp'])->middleware('throttle:10,1');
            Route::post('/companies/{company}/messages', [AdminCompanyController::class, 'sendMessage'])->middleware('throttle:20,1');
            Route::delete('/companies/{company}/messages/{message}', [AdminCompanyController::class, 'deleteMessage'])->whereNumber('message');
            Route::post('/companies/{company}/impersonate', [AdminCompanyController::class, 'impersonate'])->middleware('throttle:10,1');
            Route::get('/announcements', [AdminAnnouncementController::class, 'index']);
            Route::post('/announcements', [AdminAnnouncementController::class, 'store']);
            Route::put('/announcements/{announcement}', [AdminAnnouncementController::class, 'update']);
            Route::delete('/announcements/{announcement}', [AdminAnnouncementController::class, 'destroy']);
            Route::get('/system', AdminSystemController::class);
            Route::get('/finance', [AdminFinanceController::class, 'index']);
            Route::post('/finance/sync', [AdminFinanceController::class, 'sync'])->middleware('throttle:3,1');
            Route::post('/finance/costs', [AdminFinanceController::class, 'storeCost']);
            Route::put('/finance/costs/{cost}', [AdminFinanceController::class, 'updateCost']);
            Route::delete('/finance/costs/{cost}', [AdminFinanceController::class, 'destroyCost']);
            Route::get('/users', [AdminUserController::class, 'index']);
            Route::post('/users/{user}/password-link', [AdminUserController::class, 'passwordLink'])->middleware('throttle:20,1');
            Route::get('/support', [AdminSupportController::class, 'index']);
            Route::get('/support/summary', [AdminSupportController::class, 'summary']);
            Route::get('/support/{ticket}', [AdminSupportController::class, 'show']);
            Route::post('/support/{ticket}/reply', [AdminSupportController::class, 'reply']);
            Route::patch('/support/{ticket}/status', [AdminSupportController::class, 'status']);
            Route::get('/feedback', [AdminFeedbackController::class, 'index']);
            Route::patch('/feedback/{feedback}', [AdminFeedbackController::class, 'update'])->whereNumber('feedback');
            Route::get('/testimonials', [AdminTestimonialController::class, 'index']);
            Route::patch('/testimonials/{testimonial}', [AdminTestimonialController::class, 'update'])->whereNumber('testimonial');
        });

        // Ajuda e suporte: fora do bloqueio da conta — loja suspensa ou com exclusão agendada também pede ajuda
        Route::get('/support', [SupportTicketController::class, 'index']);
        Route::get('/support/unread', [SupportTicketController::class, 'unread']);
        Route::post('/support', [SupportTicketController::class, 'store'])->middleware('throttle:10,60');
        Route::get('/support/{ticket}', [SupportTicketController::class, 'show']);
        Route::post('/support/{ticket}/reply', [SupportTicketController::class, 'reply'])->middleware('throttle:30,1');
        Route::post('/support/{ticket}/resolve', [SupportTicketController::class, 'resolve']);
        Route::post('/support/{ticket}/rating', [SupportTicketController::class, 'rate'])->middleware('throttle:10,1');

        // LGPD — liberadas mesmo com a exclusão da conta agendada (baixar os dados, desfazer a exclusão)
        Route::post('/account/accept-terms', [AccountController::class, 'acceptTerms']);
        Route::get('/account/export', [AccountController::class, 'export'])->middleware('throttle:5,10');
        Route::delete('/account/deletion', [AccountController::class, 'cancelDeletion']);

        // Todo o resto fica parado enquanto a exclusão estiver agendada (inclui o bot, que usa token de integração)
        Route::middleware(EnsureAccountActive::class)->group(function () {
            Route::post('/account/deletion', [AccountController::class, 'requestDeletion'])->middleware('throttle:5,1');

            // Opinião sobre a plataforma (NPS / fim do teste) — só o dono (sem regra em TeamAccess)
            Route::get('/feedback/prompt', [PlatformFeedbackController::class, 'prompt']);
            Route::post('/feedback', [PlatformFeedbackController::class, 'store'])->middleware('throttle:10,1');
            Route::post('/feedback/dismiss', [PlatformFeedbackController::class, 'dismiss'])->middleware('throttle:10,1');
            // Indique e ganhe e depoimento (só o dono)
            Route::get('/referrals', [ReferralController::class, 'show']);
            Route::get('/testimonial', [TestimonialController::class, 'show']);
            Route::put('/testimonial', [TestimonialController::class, 'save'])->middleware('throttle:10,1');
            Route::delete('/testimonial', [TestimonialController::class, 'destroy']);

            // Importar planilha (produtos e insumos: só o dono; clientes: dono e vendas — ver TeamAccess)
            Route::post('/products/import', [ImportController::class, 'products'])->middleware('throttle:30,1');
            Route::get('/products/import/photos/{batch}', [ImportController::class, 'photos']);
            Route::post('/materials/import', [ImportController::class, 'materials'])->middleware('throttle:30,1');
            Route::post('/customers/import', [ImportController::class, 'customers'])->middleware('throttle:30,1');
            Route::get('/materials/{material}/movements', [MaterialController::class, 'movements']);
            Route::apiResource('materials', MaterialController::class);
            Route::apiResource('printers', PrinterController::class);
            Route::get('/customers/duplicates', [CustomerController::class, 'duplicates']);
            Route::post('/customers/merge', [CustomerController::class, 'merge']);
            Route::get('/customers/{customer}/profile', [CustomerController::class, 'profile']);
            Route::post('/customers/{customer}/instagram-invite', [CustomerController::class, 'instagramInvite']);
            Route::get('/customers/{customer}/cashback', [CustomerController::class, 'cashback']);
            Route::post('/customers/{customer}/cashback/adjust', [CustomerController::class, 'adjustCashback']);
            Route::put('/customers/{customer}/referrer', [CustomerController::class, 'setReferrer']);
            Route::apiResource('customers', CustomerController::class);
            Route::get('/material-categories', [MaterialCategoryController::class, 'index']);
            Route::post('/material-categories', [MaterialCategoryController::class, 'store']);
            Route::put('/material-categories/{materialCategory}', [MaterialCategoryController::class, 'update']);
            Route::delete('/material-categories/{materialCategory}', [MaterialCategoryController::class, 'destroy']);

            Route::get('/product-categories', [ProductCategoryController::class, 'index']);
            Route::post('/product-categories', [ProductCategoryController::class, 'store']);
            Route::put('/product-categories/{productCategory}', [ProductCategoryController::class, 'update']);
            Route::delete('/product-categories/{productCategory}', [ProductCategoryController::class, 'destroy']);
            Route::get('/product-reviews', [ProductReviewController::class, 'index']);
            Route::patch('/product-reviews/{productReview}/moderate', [ProductReviewController::class, 'moderate']);
            Route::delete('/product-reviews/{productReview}', [ProductReviewController::class, 'destroy']);
            Route::post('/quotes/{quote}/review-link', [ProductReviewController::class, 'link']);
            Route::get('/product-collections', [ProductCollectionController::class, 'index']);
            Route::post('/product-collections', [ProductCollectionController::class, 'store']);
            Route::put('/product-collections/{productCollection}', [ProductCollectionController::class, 'update']);
            Route::delete('/product-collections/{productCollection}', [ProductCollectionController::class, 'destroy']);
            Route::post('/product-collections/{productCollection}/banner', [ProductCollectionController::class, 'uploadBanner']);
            Route::delete('/product-collections/{productCollection}/banner', [ProductCollectionController::class, 'destroyBanner']);

            Route::get('/sales-channels', [SalesChannelController::class, 'index']);
            Route::post('/sales-channels', [SalesChannelController::class, 'store']);
            Route::put('/sales-channels/{salesChannel}', [SalesChannelController::class, 'update']);
            Route::delete('/sales-channels/{salesChannel}', [SalesChannelController::class, 'destroy']);

            Route::get('/displays', [DisplayController::class, 'index']);
            Route::post('/displays', [DisplayController::class, 'store']);
            Route::get('/displays/{display}', [DisplayController::class, 'show']);
            Route::put('/displays/{display}', [DisplayController::class, 'update']);
            Route::delete('/displays/{display}', [DisplayController::class, 'destroy']);
            Route::post('/displays/{display}/restock', [DisplayController::class, 'restock']);
            Route::post('/displays/{display}/retrieve', [DisplayController::class, 'retrieve']);
            Route::post('/displays/{display}/reconcile', [DisplayController::class, 'reconcile']);
            Route::post('/displays/{display}/close', [DisplayController::class, 'close']);
            Route::post('/displays/{display}/settle', [DisplayController::class, 'settle']);
            Route::post('/displays/{display}/partner-link', [DisplayController::class, 'sendPartnerLink']);
            Route::get('/displays/{display}/qr-stats', [DisplayController::class, 'qrStats']);
            Route::get('/displays/{display}/qr-code', [DisplayController::class, 'qrImage']);
            Route::get('/products/generate-sku', [ProductController::class, 'generateSku']);
            Route::post('/products/cost-preview', [ProductController::class, 'costPreview']);
            Route::post('/products/{product}/production', [ProductController::class, 'registerProduction']);
            Route::post('/products/{product}/images', [ProductController::class, 'uploadImages']);
            Route::delete('/products/{product}/images/{image}', [ProductController::class, 'destroyImage']);
            Route::patch('/products/{product}/images/reorder', [ProductController::class, 'reorderImages']);
            Route::apiResource('products', ProductController::class);

            Route::post('/quotes/preview', [QuoteController::class, 'preview']);
            Route::post('/quotes/quick-sale', [QuoteController::class, 'quickSale']);
            Route::post('/quotes/external-order', [QuoteController::class, 'externalOrder']);
            // Mensagens do pagamento do pedido do bot (QR, código Pix e link do cartão), que o n8n manda separadas
            Route::get('/whatsapp/order-payment', [QuoteController::class, 'botOrderPayment']);
            Route::post('/quotes/external-quote-request', [QuoteRequestController::class, 'externalStore']);
            Route::get('/quotes/{quote}/pdf', [QuoteController::class, 'pdf']);
            // Cobrança de venda entregue a receber (junta as do mesmo cliente)
            Route::get('/quotes/{quote}/charge', [QuoteChargeController::class, 'show']);
            Route::post('/quotes/charge', [QuoteChargeController::class, 'send'])->middleware('throttle:30,1');
            // Pix pelo Mercado Pago (Pro): conexão da conta (só o dono) e Pix da venda (dono e vendas)
            Route::get('/payments/mercadopago', [MercadoPagoController::class, 'show']);
            Route::post('/payments/mercadopago/connect', [MercadoPagoController::class, 'connect'])->middleware('throttle:10,1');
            Route::delete('/payments/mercadopago', [MercadoPagoController::class, 'disconnect']);
            Route::get('/quotes/{quote}/pix', [PixChargeController::class, 'index']);
            Route::post('/quotes/{quote}/pix', [PixChargeController::class, 'store'])->middleware('throttle:20,1');
            Route::get('/quotes/{quote}/pix/{charge}', [PixChargeController::class, 'show'])->middleware('throttle:120,1');
            Route::post('/quotes/{quote}/pix/{charge}/refund', [PixChargeController::class, 'refund'])->middleware('throttle:10,1');
            Route::post('/quotes/{quote}/pix/{charge}/send', [PixChargeController::class, 'send'])->middleware('throttle:20,1');
            Route::put('/payments/mercadopago/rules', [MercadoPagoController::class, 'updateRules']);

            Route::get('/quote-requests', [QuoteRequestController::class, 'index']);
            Route::get('/quote-requests/{quoteRequest}', [QuoteRequestController::class, 'show']);
            Route::patch('/quote-requests/{quoteRequest}', [QuoteRequestController::class, 'update']);
            Route::delete('/quote-requests/{quoteRequest}', [QuoteRequestController::class, 'destroy']);
            Route::post('/quote-requests/{quoteRequest}/link-quote', [QuoteRequestController::class, 'linkQuote']);

            Route::get('/integration-tokens', [IntegrationTokenController::class, 'index']);
            Route::post('/integration-tokens', [IntegrationTokenController::class, 'store']);
            Route::delete('/integration-tokens/{integrationToken}', [IntegrationTokenController::class, 'destroy']);

            Route::get('/whatsapp', [WhatsAppConnectionController::class, 'show']);
            Route::get('/whatsapp/settings', [WhatsAppConnectionController::class, 'settings']);
            Route::put('/whatsapp/settings', [WhatsAppConnectionController::class, 'updateSettings']);
            Route::post('/whatsapp/connect', [WhatsAppConnectionController::class, 'connect']);
            Route::post('/whatsapp/disconnect', [WhatsAppConnectionController::class, 'disconnect']);
            Route::patch('/whatsapp/bot-status', [WhatsAppConnectionController::class, 'toggleBot']);
            Route::get('/whatsapp/handoffs', [WhatsAppHandoffController::class, 'index']);
            Route::post('/whatsapp/handoffs', [WhatsAppHandoffController::class, 'store']);
            Route::get('/whatsapp/handoffs/{phone}', [WhatsAppHandoffController::class, 'show']);
            // Bot: saldo de cashback de quem está conversando
            Route::get('/whatsapp/cashback/{phone}', [CustomerController::class, 'cashbackByPhone']);
            // Bot: conversa que veio do QR de um expositor (mensagem pronta com "Expositor CODIGO")
            Route::post('/whatsapp/display-contacts', [DisplayController::class, 'qrContact']);
            Route::delete('/whatsapp/handoffs/{phone}', [WhatsAppHandoffController::class, 'destroy']);
            Route::patch('/quotes/{quote}/approve', [QuoteController::class, 'approve']);
            Route::patch('/quotes/{quote}/reject', [QuoteController::class, 'reject']);
            Route::patch('/quotes/{quote}/cancel', [QuoteController::class, 'cancel']);
            Route::patch('/quotes/{quote}/payment', [QuoteController::class, 'updatePayment']);
            Route::patch('/quotes/{quote}/delivery', [QuoteController::class, 'updateDelivery']);
            // Agenda (Pro): entregas por período + atrasadas
            Route::get('/agenda', [AgendaController::class, 'index']);
            Route::get('/agenda/availability', [DeliveryDatesController::class, 'availability']);
            Route::put('/settings/delivery', [SettingsController::class, 'updateDelivery']);
            // Bot do WhatsApp: próximas datas livres (token de integração — está em RestrictIntegrationTokens::ALLOWED)
            Route::get('/whatsapp/delivery-dates', [DeliveryDatesController::class, 'botDates']);
            Route::patch('/quotes/{quote}/pause', [QuoteController::class, 'pause']);
            Route::patch('/quotes/{quote}/resume', [QuoteController::class, 'resume']);
            Route::patch('/quotes/{quote}/production-status', [QuoteController::class, 'updateProductionStatus']);
            Route::patch('/quotes/production-order', [QuoteController::class, 'reorderProduction']);
            // Antes do resource (senão "dashboard" viraria o {quote})
            Route::get('/quotes/dashboard', [QuoteController::class, 'dashboard']);
            Route::get('/quotes/team-summary', [QuoteController::class, 'teamSummary']);
            Route::apiResource('quotes', QuoteController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

            Route::get('/settings', [SettingsController::class, 'show']);
            Route::put('/settings', [SettingsController::class, 'update']);
            Route::put('/settings/segment', [SettingsController::class, 'updateSegment']);
            Route::put('/settings/name', [SettingsController::class, 'updateName']);
            Route::put('/settings/cashback', [SettingsController::class, 'updateCashback']);
            Route::get('/settings/cashback/retroactive', [SettingsController::class, 'cashbackRetroactivePreview']);
            Route::post('/settings/cashback/retroactive', [SettingsController::class, 'cashbackRetroactiveApply']);
            Route::get('/settings/cashback/notifications', [SettingsController::class, 'cashbackNotificationsStatus']);
            Route::get('/settings/cashback/image-preview', [SettingsController::class, 'cashbackImagePreview']);

            Route::get('/catalog', [CatalogController::class, 'show']);
            Route::get('/catalog/analytics', [CatalogController::class, 'analytics']);
            Route::patch('/catalog', [CatalogController::class, 'update']);
            Route::post('/catalog/regenerate', [CatalogController::class, 'regenerate']);
            Route::patch('/catalog/slug', [CatalogController::class, 'updateSlug']);
            Route::post('/catalog/logo', [CatalogController::class, 'uploadLogo']);
            Route::delete('/catalog/logo', [CatalogController::class, 'destroyLogo']);
            Route::get('/catalog/domain', [CatalogController::class, 'domainShow']);
            Route::put('/catalog/domain', [CatalogController::class, 'domainUpdate']);
            Route::post('/catalog/domain/verify', [CatalogController::class, 'domainVerify']);
            Route::delete('/catalog/domain', [CatalogController::class, 'domainDestroy']);
            // Domínio próprio da área do parceiro (parceiro.empresa.com.br)
            Route::get('/catalog/partner-domain', [CatalogController::class, 'partnerDomainShow']);
            Route::put('/catalog/partner-domain', [CatalogController::class, 'partnerDomainUpdate']);
            Route::post('/catalog/partner-domain/verify', [CatalogController::class, 'partnerDomainVerify']);
            Route::delete('/catalog/partner-domain', [CatalogController::class, 'partnerDomainDestroy']);
            // Domínio próprio dos links de ofertas (grupo.empresa.com.br/ofertas/...)
            Route::get('/catalog/offers-domain', [CatalogController::class, 'offersDomainShow']);
            Route::put('/catalog/offers-domain', [CatalogController::class, 'offersDomainUpdate']);
            Route::post('/catalog/offers-domain/verify', [CatalogController::class, 'offersDomainVerify']);
            Route::delete('/catalog/offers-domain', [CatalogController::class, 'offersDomainDestroy']);
            Route::post('/catalog/banners', [CatalogController::class, 'uploadBanner']);
            Route::patch('/catalog/banners/reorder', [CatalogController::class, 'reorderBanners']);
            Route::patch('/catalog/banners/{banner}', [CatalogController::class, 'updateBanner']);
            Route::delete('/catalog/banners/{banner}', [CatalogController::class, 'destroyBanner']);

            Route::get('/reports', [ReportController::class, 'show']);

            Route::get('/plan', [PlanController::class, 'show']);
            Route::post('/plan/checkout', [PlanController::class, 'checkout']);
            Route::post('/plan/portal', [PlanController::class, 'portal']);

            Route::get('/subscription', [SubscriptionController::class, 'show']);
            Route::post('/subscription/cancel', [SubscriptionController::class, 'cancel']);
            Route::post('/subscription/resume', [SubscriptionController::class, 'resume']);

            Route::put('/profile', [ProfileController::class, 'update']);
            Route::put('/profile/password', [ProfileController::class, 'updatePassword']);

            // Grupos e canais de ofertas no WhatsApp (Pro, só o dono)
            Route::get('/offers', [OfferController::class, 'index']);
            Route::get('/offers/channels', [OfferController::class, 'channels']);
            Route::get('/offers/schedule', [OfferController::class, 'schedule']);
            Route::put('/offers/schedule', [OfferController::class, 'updateSchedule']);
            Route::post('/offers', [OfferController::class, 'store'])->middleware('throttle:10,1');
            Route::post('/offers/post', [OfferController::class, 'post'])->middleware('throttle:30,1');
            Route::get('/offers/social', [OfferController::class, 'social']);
            Route::post('/offers/social', [OfferController::class, 'postSocial'])->middleware('throttle:10,1');
            Route::post('/offers/preview', [OfferController::class, 'preview']);
            Route::post('/offers/ai-text', [OfferController::class, 'aiText'])->middleware('throttle:20,1');
            Route::put('/products/{product}/offer-text', [OfferController::class, 'updateProductText']);
            Route::put('/offers/{series}', [OfferController::class, 'update']);
            Route::post('/offers/{series}/image', [OfferController::class, 'uploadImage'])->middleware('throttle:10,1');
            Route::delete('/offers/{series}/image', [OfferController::class, 'destroyImage']);
            Route::delete('/offers/{series}', [OfferController::class, 'destroy']);
            Route::post('/offers/{series}/check', [OfferController::class, 'check'])->middleware('throttle:10,1');
            Route::patch('/products/{product}/offers', [OfferController::class, 'toggleProduct']);
            // Achadinhos (links de afiliado de outras lojas). Editar é POST por causa da foto (multipart).
            Route::get('/affiliate-offers', [AffiliateOfferController::class, 'index']);
            Route::post('/affiliate-offers/read-link', [AffiliateOfferController::class, 'readLink'])->middleware('throttle:20,1');
            Route::post('/affiliate-offers/preview', [AffiliateOfferController::class, 'previewMessage']);
            Route::post('/affiliate-offers/ai-text', [AffiliateOfferController::class, 'aiText'])->middleware('throttle:20,1');
            Route::post('/affiliate-offers/bulk', [AffiliateOfferController::class, 'bulkStore'])->middleware('throttle:10,1');
            // Shopee (API oficial de afiliados): conta, busca, importar e comissões
            Route::get('/affiliate-accounts', [AffiliateIntegrationController::class, 'show']);
            Route::put('/affiliate-accounts/shopee', [AffiliateIntegrationController::class, 'connectShopee'])->middleware('throttle:10,1');
            Route::delete('/affiliate-accounts/shopee', [AffiliateIntegrationController::class, 'disconnectShopee']);
            Route::get('/affiliate-offers/shopee/search', [AffiliateIntegrationController::class, 'searchShopee'])->middleware('throttle:30,1');
            Route::post('/affiliate-offers/shopee/import', [AffiliateIntegrationController::class, 'importShopee'])->middleware('throttle:10,1');
            Route::get('/affiliate-offers/shopee/commissions', [AffiliateIntegrationController::class, 'shopeeCommissions'])->middleware('throttle:10,1');
            Route::post('/affiliate-offers', [AffiliateOfferController::class, 'store'])->middleware('throttle:30,1');
            Route::post('/affiliate-offers/{offer}', [AffiliateOfferController::class, 'update'])->middleware('throttle:60,1');
            Route::delete('/affiliate-offers/{offer}', [AffiliateOfferController::class, 'destroy']);
            Route::post('/affiliate-offers/{offer}/send', [AffiliateOfferController::class, 'send'])->middleware('throttle:30,1');

            // Cupons de desconto (Pro, só o dono cadastra; a equipe só aplica na venda)
            Route::get('/coupons/automations/reactivation', [CouponAutomationController::class, 'show']);
            Route::put('/coupons/automations/reactivation', [CouponAutomationController::class, 'update']);
            Route::get('/coupons', [CouponController::class, 'index']);
            Route::post('/coupons', [CouponController::class, 'store']);
            Route::get('/coupons/{coupon}/orders', [CouponController::class, 'orders']);
            Route::get('/coupons/{coupon}/broadcast', [CouponController::class, 'broadcastPreview']);
            Route::post('/coupons/{coupon}/broadcast', [CouponController::class, 'broadcast'])->middleware('throttle:10,1');
            Route::put('/coupons/{coupon}', [CouponController::class, 'update']);
            Route::delete('/coupons/{coupon}', [CouponController::class, 'destroy']);

            // Primeiros passos do Dashboard (só o dono)
            Route::get('/onboarding', [OnboardingController::class, 'show']);

            // Equipe (só o dono)
            Route::get('/team', [TeamController::class, 'index']);
            Route::post('/team', [TeamController::class, 'store'])->middleware('throttle:20,1');
            Route::put('/team/{user}', [TeamController::class, 'update']);
            Route::delete('/team/{user}', [TeamController::class, 'destroy']);
            Route::post('/team/{user}/resend', [TeamController::class, 'resend'])->middleware('throttle:10,1');
        });
    });
});