<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Aceite dos Termos de Uso e da Política de Privacidade (versão em config/legal.php)
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('terms_accepted_at')->nullable()->after('password');
            $table->string('terms_version', 20)->nullable()->after('terms_accepted_at');
            $table->string('terms_accepted_ip', 45)->nullable()->after('terms_version');
        });

        // Exclusão de conta agendada: até a data, o lojista pode desfazer; depois, account:purge-deleted apaga tudo
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('deletion_scheduled_for')->nullable()->index();
            // A assinatura foi cancelada pelo pedido de exclusão (desfazer reativa só nesse caso)
            $table->boolean('deletion_paused_subscription')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['terms_accepted_at', 'terms_version', 'terms_accepted_ip']);
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['deletion_scheduled_for']);
            $table->dropColumn(['deletion_scheduled_for', 'deletion_paused_subscription']);
        });
    }
};
