<?php
/**
 * MRR Analytics — receita recorrente mensal do seu WHMCS.
 *
 * Mostra a evolucao mes a mes (nao um retrato repetido), separa o que esta
 * suspenso, lista TODOS os produtos e guarda um retrato fechado de cada mes
 * para que o historico nao dependa de reconstrucao.
 *
 * A interface usa as classes do proprio WHMCS, para acompanhar o tema do
 * painel em vez de impor cores proprias.
 *
 * @version 2.3.0
 * @author  Hostcel
 */

if (!defined('WHMCS')) {
    die('Acesso direto nao permitido.');
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/calc.php';
require_once __DIR__ . '/admin/index.php';

function mrr_config()
{
    return [
        'name'        => 'MRR Analytics',
        'description' => 'Mostra o DINHEIRO recebido mes a mes, por produto, mais a receita '
            . 'recorrente (MRR), novos contratos e cancelamentos. Relatorio opcional por WhatsApp.',
        'version'     => MRR_VERSION,
        'author'      => "<a href='https://www.hostcel.com.br' target='_blank'>Hostcel</a>",
        'language'    => 'portuguese-br',
        'fields'      => [
            'whatsapp_enabled' => [
                'FriendlyName' => 'Relatorio por WhatsApp',
                'Type'         => 'yesno',
                'Description'  => 'Envia o resumo do mes fechado. Requer o cron abaixo.',
                'Default'      => 'off',
            ],
            'report_frequency' => [
                'FriendlyName' => 'Frequencia',
                'Type'         => 'dropdown',
                'Options'      => [
                    'daily'   => 'Diario',
                    'weekly'  => 'Semanal (segunda-feira)',
                    'monthly' => 'Mensal (dia 1)',
                ],
                'Default'      => 'monthly',
            ],
            'report_hour' => [
                'FriendlyName' => 'Horario',
                'Type'         => 'text',
                'Size'         => '5',
                'Default'      => '09',
                'Description'  => 'Hora cheia, 00 a 23.',
            ],
            'whatsapp_url' => [
                'FriendlyName' => 'URL da API',
                'Type'         => 'text',
                'Size'         => '80',
                'Description'  => 'Endpoint da sua API de WhatsApp.',
            ],
            'whatsapp_instance_id' => [
                'FriendlyName' => 'Instance ID',
                'Type'         => 'text',
                'Size'         => '40',
                'Description'  => 'Credencial da sua instancia.',
            ],
            'whatsapp_access_token' => [
                'FriendlyName' => 'Access Token',
                'Type'         => 'password',
                'Size'         => '40',
                'Description'  => 'Credencial da sua instancia.',
            ],
            'sobre' => [
                'FriendlyName' => 'Sobre',
                'Description'  => 'MRR Analytics v' . MRR_VERSION . ' &copy; ' . date('Y')
                    . ' Hostcel. Cron do relatorio: <kbd>0 * * * * php '
                    . rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/')
                    . '/modules/addons/mrr/api.php</kbd>',
            ],
        ],
    ];
}

function mrr_activate()
{
    try {
        if (!Capsule::schema()->hasTable(MRR_TABLE_HISTORY)) {
            Capsule::schema()->create(MRR_TABLE_HISTORY, function ($t) {
                $t->increments('id');
                $t->date('period_date');
                $t->decimal('total_mrr', 15, 2)->default(0);
                $t->decimal('services_mrr', 15, 2)->default(0);
                $t->decimal('domains_mrr', 15, 2)->default(0);
                $t->decimal('expansion_mrr', 15, 2)->default(0);
                $t->decimal('churn_mrr', 15, 2)->default(0);
                $t->text('categories_breakdown')->nullable();
                $t->timestamp('calculated_at')->nullable();
                $t->unique('period_date');
            });
        }

        if (!Capsule::schema()->hasTable(MRR_TABLE_LOGS)) {
            Capsule::schema()->create(MRR_TABLE_LOGS, function ($t) {
                $t->increments('id');
                $t->string('report_type', 20);
                $t->text('phone_numbers');
                $t->text('message_content');
                $t->integer('total_sent')->default(0);
                $t->integer('total_failed')->default(0);
                $t->timestamp('sent_at')->nullable();
                $t->text('response_log')->nullable();
            });
        }

        // Nasce com historico: reconstroi os 12 meses fechados anteriores.
        $gravados = 0;
        for ($i = 12; $i >= 1; $i--) {
            if (mrr_store_snapshot(mrr_snapshot(mrr_month_start($i)))) {
                $gravados++;
            }
        }

        return [
            'status'      => 'success',
            'description' => 'MRR Analytics v' . MRR_VERSION . ' ativado. '
                . $gravados . ' meses de historico reconstruidos.',
        ];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Erro ao ativar: ' . $e->getMessage()];
    }
}

function mrr_deactivate()
{
    // Historico preservado: reativar nao recomeca do zero.
    return ['status' => 'success', 'description' => 'Desativado. Historico preservado.'];
}

function mrr_upgrade($vars)
{
    try {
        // A 1.x nunca gravou retrato nenhum: repopula ao atualizar.
        if (Capsule::schema()->hasTable(MRR_TABLE_HISTORY)) {
            for ($i = 12; $i >= 1; $i--) {
                mrr_store_snapshot(mrr_snapshot(mrr_month_start($i)));
            }
        }
    } catch (\Throwable $e) {
        // upgrade nao pode derrubar a ativacao
    }

    return ['status' => 'success', 'description' => 'Atualizado para v' . MRR_VERSION];
}

function mrr_output($vars)
{
    mrr_admin_dispatcher($vars);
}

/**
 * Barra lateral: identidade do modulo, nao navegacao.
 * As abas ja estao no topo da tela; repetir os links aqui era ruido.
 */
function mrr_sidebar($vars)
{
    $latest = mrr_latest();
    $nova   = $latest && version_compare((string) $latest['version'], MRR_VERSION, '>');

    $s  = '<div class="panel panel-default">';
    $s .= '<div class="panel-body text-center">';
    $s .= '<a href="' . MRR_SITE . '" target="_blank" rel="noopener">';
    $s .= '<img src="' . MRR_LOGO . '" alt="Hostcel" style="max-width:150px;max-height:46px;margin-bottom:10px;">';
    $s .= '</a>';
    $s .= '<h4 style="margin:6px 0 4px;font-weight:600;">MRR Analytics</h4>';
    $s .= '<p class="text-muted" style="font-size:12px;margin-bottom:10px;">'
        . 'Dinheiro recebido mes a mes e receita recorrente do seu WHMCS, com quebra por produto '
        . 'e cancelamentos.</p>';
    $s .= '<span class="label label-' . ($nova ? 'warning' : 'default') . '">v' . MRR_VERSION . '</span>';
    if ($nova) {
        $s .= ' <span class="label label-success">v' . htmlspecialchars((string) $latest['version']) . ' disponivel</span>';
    }
    $s .= '</div>';
    $s .= '<div class="panel-footer text-center" style="font-size:11px;">';
    $s .= '<a href="' . MRR_SITE . '" target="_blank" rel="noopener">hostcel.com.br</a>';
    $s .= ' &middot; &copy; ' . date('Y') . ' Hostcel';
    $s .= '</div>';
    $s .= '</div>';

    return $s;
}
