<?php
/**
 * MRR Analytics — interface administrativa.
 *
 * Estrutura: 4 indicadores, 2 graficos e a tabela de produtos — a mesma que o
 * dono aprovou. O visual segue o padrao do modulo hostcelapp: Bootstrap do
 * proprio WHMCS (panel / table / nav-tabs / label) com uma camada fina de CSS
 * escopada em `.mrr`, so para os acentos. Nada de layout proprio.
 *
 * Chart.js vem de assets/ do proprio modulo — sem CDN.
 *
 * @version 2.3.0
 * @author  Hostcel
 */

if (!defined('WHMCS')) {
    die('Acesso não autorizado.');
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../calc.php';

function mrr_admin_dispatcher($vars)
{
    $action = isset($_GET['action']) ? $_GET['action'] : 'dashboard';

    switch ($action) {
        case 'history':
            mrr_render_history($vars);
            break;
        case 'test_whatsapp':
            mrr_render_test_whatsapp($vars);
            break;
        case 'update':
            mrr_render_update($vars);
            break;
        case 'market':
            mrr_render_market($vars);
            break;
        default:
            mrr_render_dashboard($vars);
    }
}

/** Monta uma URL da propria area admin trocando apenas a action. */
function mrr_admin_url($action)
{
    $params = $_GET;
    $params['action'] = $action;

    return 'addonmodules.php?' . http_build_query($params);
}

/** Chart.js local + a camada fina de CSS (padrao hostcelapp). */
function mrr_admin_assets()
{
    // Font Awesome ja vem com o WHMCS; Chart.js e servido pelo proprio modulo.
    echo '<script src="modules/addons/mrr/assets/chart.umd.min.js"></script>';
    ?>
    <style>
    /* — cabecalho, no padrao do hostcelapp — */
    .mrr{padding:0 5px}
    .mrr .mrr-header{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:15px;padding:15px;background:linear-gradient(90deg,#2c7be5 0%,#1a68d1 100%);color:#fff;border-radius:6px}
    .mrr .mrr-header h2{margin:0;color:#fff;font-weight:600;font-size:22px}
    .mrr .mrr-header small{opacity:.85}
    .mrr .mrr-header .badge-version{background:rgba(255,255,255,.2);padding:5px 12px;border-radius:20px;font-size:12px;white-space:nowrap}

    /* — abas: so o acento azul embaixo; as cores ficam com o tema — */
    .mrr .mrr-tabs{border-bottom:2px solid #2c7be5;margin-bottom:20px}
    .mrr .mrr-tabs > li > a{font-weight:500}
    .mrr .mrr-tabs > li.active > a{border-bottom-color:transparent}

    /* — indicadores: panel do Bootstrap com acento na borda — */
    .mrr .mrr-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-bottom:20px}
    .mrr .mrr-stat{border-left:4px solid #3d8bd6;margin-bottom:0;overflow:hidden}
    .mrr .mrr-stat.up{border-left-color:#28a745}
    .mrr .mrr-stat.down{border-left-color:#d9534f}
    .mrr .mrr-stat.arr{border-left-color:#6f42c1}
    .mrr .mrr-stat .k{font-size:11px;text-transform:uppercase;letter-spacing:.5px;opacity:.7}
    .mrr .mrr-stat .v{font-size:20px;font-weight:600;line-height:1.4}
    .mrr .mrr-stat .s{font-size:11px;opacity:.7}

    /* — cards e tabelas — */
    .mrr .mrr-card{overflow:hidden}

    /* O campo de telefone usa o seletor de paises do proprio WHMCS
       (assets/js/TelephoneCountryCodeDropdown.js). A lista e posicionada
       absoluta e era cortada pelo overflow do card; este bloco libera o
       recorte e poe a lista acima do resto. */
    .mrr .mrr-card.mrr-overflow{overflow:visible}
    .mrr .mrr-card.mrr-overflow > .panel-body{overflow:visible}
    .mrr .iti{width:100%}
    .mrr .iti__country-list,
    .mrr .country-list{z-index:1200}
    .mrr .iti--container{z-index:1200}
    .mrr .mrr-card > .panel-heading{font-weight:600;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
    .mrr .mrr-table th{font-size:11px;text-transform:uppercase;letter-spacing:.5px}
    .mrr .mrr-chart{position:relative;height:240px}
    .mrr .mrr-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:15px}

    /* — vitrine de modulos: mesma grade e mesmo card do hostcelapp — */
    .mrr .mrr-market-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}
    .mrr .mrr-market-card{overflow:hidden;margin-bottom:0;display:flex;flex-direction:column;max-width:400px}
    .mrr .mrr-market-card > .panel-body{padding:15px;flex:1}
    .mrr .mrr-market-card img{width:100%;height:auto;display:block}
    .mrr .mrr-tight{margin-top:0}
    .mrr .mrr-swatch{display:inline-block;width:12px;height:12px;border-radius:3px;margin-right:6px;vertical-align:middle}
    </style>
    <?php
}

/** Cabecalho + abas, repetidos nas tres telas. */
function mrr_admin_header($titulo, $subtitulo, $active)
{
    mrr_admin_assets();
    echo '<div class="mrr">';
    echo '<div class="mrr-header"><div><h2>' . $titulo . '</h2><small>' . $subtitulo . '</small></div>';
    echo '<span class="badge-version">v' . MRR_VERSION . '</span></div>';

    $latest = mrr_latest();
    $nova   = $latest && version_compare((string) $latest['version'], MRR_VERSION, '>');

    $tabs = [
        'dashboard'     => '<i class="fas fa-chart-line"></i> Dashboard',
        'history'       => '<i class="fas fa-history"></i> Histórico (12 meses)',
        'test_whatsapp' => '<i class="fab fa-whatsapp"></i> Testar WhatsApp',
        // Mesma ordem do hostcelapp: Modulos gratis antes de Atualizacoes.
        'market'        => '<i class="fas fa-gift"></i> Módulos grátis',
        'update'        => '<i class="fas fa-cloud-download-alt"></i> Atualizações'
                           . ($nova ? ' <span class="label label-success">nova</span>' : ''),
    ];
    echo '<ul class="nav nav-tabs mrr-tabs">';
    foreach ($tabs as $a => $label) {
        echo '<li' . ($active === $a ? ' class="active"' : '') . '>'
            . '<a href="' . htmlspecialchars(mrr_admin_url($a)) . '">' . $label . '</a></li>';
    }
    echo '</ul>';
}

/**
 * Serializa uma lista de numeros para o JS com 2 casas.
 *
 * `json_encode` de float no PHP sai com a expansao binaria inteira
 * (18900.72999999999956344254...), o que incha o HTML sem necessidade.
 */
function mrr_json_nums(array $valores)
{
    return '[' . implode(',', array_map(function ($v) {
        return number_format((float) $v, 2, '.', '');
    }, $valores)) . ']';
}

/** Indicador no padrao panel + acento. */
function mrr_stat($rotulo, $valor, $sub, $classe = '')
{
    echo '<div class="panel panel-default mrr-stat ' . $classe . '"><div class="panel-body">';
    echo '<div class="k">' . $rotulo . '</div>';
    echo '<div class="v">' . $valor . '</div>';
    echo '<div class="s">' . $sub . '</div>';
    echo '</div></div>';
}

/**
 * Calcula MRR de um periodo.
 *
 * Delega para o motor unico (calc.php). A versao antiga desta funcao
 * perguntava "quem esta Active AGORA" e usava a resposta para qualquer mes
 * passado — por isso M-1 e M-2 davam o mesmo valor e o grafico ficava reto.
 */
function mrr_calculate_for_period($startDate, $endDate)
{
    $snap = mrr_snapshot($startDate);

    return [
        'totalMRR'     => $snap['mrr_total'],
        'servicesMRR'  => $snap['by_product'],   // por produto: nenhum some
        'domainsMRR'   => $snap['mrr_domains'],
        'expansionMRR' => $snap['mrr_new'],
        'churnMRR'     => $snap['mrr_churn'],
        'count'        => $snap['count_total'],
        'countNew'     => $snap['count_new'],
        'countChurn'   => $snap['count_churn'],
    ];
}

function mrr_meses_pt()
{
    return [
        '01' => 'Janeiro', '02' => 'Fevereiro', '03' => 'Março', '04' => 'Abril',
        '05' => 'Maio', '06' => 'Junho', '07' => 'Julho', '08' => 'Agosto',
        '09' => 'Setembro', '10' => 'Outubro', '11' => 'Novembro', '12' => 'Dezembro',
    ];
}

function mrr_render_dashboard($vars)
{
    $m1Start = mrr_month_start(1);
    $m2Start = mrr_month_start(2);

    $mrrM1 = mrr_calculate_for_period($m1Start, '');
    $mrrM2 = mrr_calculate_for_period($m2Start, '');

    $t1 = $mrrM1['totalMRR'];
    $t2 = $mrrM2['totalMRR'];

    $varAbs = $t1 - $t2;
    $varPct = $t2 > 0 ? ($varAbs / $t2) * 100 : 0;
    $subiu  = $varPct >= 0;

    $meses  = mrr_meses_pt();
    $m1Name = $meses[date('m', strtotime($m1Start))] . '/' . date('Y', strtotime($m1Start));
    $m2Name = $meses[date('m', strtotime($m2Start))] . '/' . date('Y', strtotime($m2Start));

    // DINHEIRO RECEBIDO — o numero que o dono cobra em primeiro lugar.
    $recM1 = mrr_received($m1Start);
    $recM2 = mrr_received($m2Start);
    $recVar = $recM1['total'] - $recM2['total'];
    $recPct = $recM2['total'] > 0 ? ($recVar / $recM2['total']) * 100 : 0;
    $recSubiu = $recVar >= 0;

    mrr_admin_header(
        '<i class="fas fa-chart-line"></i> MRR Analytics',
        'Dinheiro recebido e receita recorrente, mês a mês',
        'dashboard'
    );

    // Mesmas 4 posicoes de sempre. O que mudou foi a FONTE do numero: agora e
    // dinheiro que entrou (tblaccounts, por data do pagamento), nao valor de
    // fatura nem projecao de contrato.
    echo '<div class="mrr-stats">';
    mrr_stat($m2Name . ' (M-2)', mrr_money($recM2['total']), $recM2['count'] . ' pagamentos recebidos');
    mrr_stat($m1Name . ' (M-1)', mrr_money($recM1['total']), $recM1['count'] . ' pagamentos recebidos');
    mrr_stat(
        '<i class="fas fa-arrow-' . ($recSubiu ? 'up' : 'down') . '"></i> '
        . ($recSubiu ? 'Crescimento' : 'Queda'),
        number_format(abs($recPct), 2, ',', '.') . '%',
        mrr_money(abs($recVar)) . ' vs M-2',
        $recSubiu ? 'up' : 'down'
    );
    // Entrou x saiu: e a resposta para "por que subiu ou caiu". Antes este card
    // era o ARR, que e so o MRR vezes 12 e nao acrescentava informacao.
    $entrou = $mrrM1['expansionMRR'];
    $saiu   = $mrrM1['churnMRR'];
    $liquido = $entrou - $saiu;

    mrr_stat(
        'Entrou &times; saiu em ' . $m1Name,
        '<span class="text-success">+' . mrr_money($entrou) . '</span>'
        . ' <span class="text-muted" style="font-weight:400;">/</span> '
        . '<span class="text-danger">&minus;' . mrr_money($saiu) . '</span>',
        $mrrM1['countNew'] . ' novos &middot; ' . $mrrM1['countChurn'] . ' cancelados'
        . ' &middot; saldo ' . ($liquido >= 0 ? '+' : '&minus;') . mrr_money(abs($liquido)),
        $liquido >= 0 ? 'up' : 'down'
    );
    echo '</div>';

    // Paleta suave, sem depender do tema.
    $cores = ['#2c7be5', '#00b8d9', '#28a745', '#f0ad4e', '#6f42c1',
              '#d9534f', '#20c997', '#fd7e14', '#6c757d', '#e83e8c'];

    // Por produto = DINHEIRO recebido, nao MRR de contrato. Um produto pode ter
    // contrato ativo e nao ter recebido nada no mes (foi o caso do Cameras
    // Cloud em julho: R$ 598 de contrato, R$ 0 recebido).
    $recProdM1 = mrr_received_by_product($m1Start);
    $recProdM2 = mrr_received_by_product($m2Start);

    $top    = array_slice($recProdM1, 0, 10, true);
    $labels = [];
    $dados  = [];
    $cor    = [];
    $i      = 0;
    foreach ($top as $nome => $v) {
        $labels[] = $nome;
        $dados[]  = round($v, 2);
        $cor[]    = $cores[$i++ % count($cores)];
    }

    // Evolucao dos ultimos 6 meses fechados: dinheiro recebido em barras
    // (o que importa) e o MRR como linha de referencia.
    $hLabels = [];
    $hDados  = [];
    $hRecebido = [];
    for ($k = 6; $k >= 1; $k--) {
        $ini       = mrr_month_start($k);
        $hLabels[] = substr($meses[date('m', strtotime($ini))], 0, 3) . '/' . date('y', strtotime($ini));
        $hDados[]  = round(mrr_snapshot($ini)['mrr_total'], 2);
        $hRecebido[] = round(mrr_received($ini)['total'], 2);
    }

    echo '<div class="mrr-row">';
    echo '<div class="panel panel-default mrr-card">';
    echo '<div class="panel-heading"><span><i class="fas fa-chart-pie"></i> Dinheiro recebido por produto</span></div>';
    echo '<div class="panel-body"><div class="mrr-chart"><canvas id="mrrCategoryChart"></canvas></div></div></div>';
    echo '<div class="panel panel-default mrr-card">';
    echo '<div class="panel-heading"><span><i class="fas fa-chart-line"></i> Recebido &times; MRR (6 meses)</span></div>';
    echo '<div class="panel-body"><div class="mrr-chart"><canvas id="mrrHistoryChart"></canvas></div></div></div>';
    echo '</div>';

    echo '<div class="panel panel-default mrr-card">';
    echo '<div class="panel-heading"><span><i class="fas fa-list"></i> Dinheiro recebido por produto em '
        . $m1Name . '</span></div>';
    echo '<div class="table-responsive"><table class="table table-striped table-condensed mrr-table">';
    echo '<thead><tr><th>Produto</th><th class="text-right">Recebido</th><th class="text-right">%</th>'
        . '<th class="text-right">Variação M-2 → M-1</th></tr></thead><tbody>';

    $totalRec = $recM1['total'] ?: 1;
    $i = 0;
    foreach ($top as $nome => $v) {
        $pct    = round(($v / $totalRec) * 100, 1);
        $antes  = $recProdM2[$nome] ?? 0;
        $delta  = $antes > 0 ? (($v - $antes) / $antes) * 100 : ($v > 0 ? 100 : 0);
        $classe = $delta < 0 ? 'danger' : 'success';
        $seta   = $delta < 0 ? '&#9660;' : '&#9650;';

        echo '<tr>';
        echo '<td><span class="mrr-swatch" style="background-color:' . $cores[$i++ % count($cores)] . ';"></span>'
            . htmlspecialchars($nome) . '</td>';
        echo '<td class="text-right"><strong>' . mrr_money($v) . '</strong></td>';
        echo '<td class="text-right">' . $pct . '%</td>';
        echo '<td class="text-right"><span class="text-' . $classe . '">' . $seta . ' '
            . number_format(abs($delta), 1, ',', '.') . '%</span></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div></div>';
    echo '</div>'; // .mrr

    ?>
    <script>
    (function () {
        if (typeof Chart === 'undefined') { return; }
        var pie = document.getElementById('mrrCategoryChart');
        if (pie) {
            new Chart(pie, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode(array_values($labels)); ?>,
                    datasets: [{ data: <?php echo mrr_json_nums($dados); ?>,
                                 backgroundColor: <?php echo json_encode($cor); ?>, borderWidth: 0 }]
                },
                options: { responsive: true, maintainAspectRatio: false, cutout: '55%',
                           plugins: { legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } } } }
            });
        }
        var line = document.getElementById('mrrHistoryChart');
        if (line) {
            new Chart(line, {
                // O 'type' aqui e obrigatorio mesmo em grafico misto: sem ele o
                // Chart.js nao registra o controlador e o grafico nao aparece.
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($hLabels); ?>,
                    datasets: [
                        { type: 'bar', label: 'Recebido', data: <?php echo mrr_json_nums($hRecebido); ?>,
                          backgroundColor: '#00a854', borderRadius: 3, order: 2 },
                        { type: 'line', label: 'MRR', data: <?php echo mrr_json_nums($hDados); ?>,
                          borderColor: '#2c7be5', backgroundColor: 'rgba(44,123,229,.12)',
                          fill: false, tension: .3, borderWidth: 2, order: 1 }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } },
                    scales: { y: { ticks: { callback: function (v) { return 'R$ ' + v.toLocaleString('pt-BR'); } } } }
                }
            });
        }
    })();
    </script>
    <?php
}

/**
 * Historico: os ultimos 12 meses de MRR.
 *
 * Antes esta aba listava os envios de WhatsApp, que nada tinham a ver com
 * "historico" de receita — o log de envios foi para a aba de teste.
 */
function mrr_render_history($vars)
{
    mrr_admin_header(
        '<i class="fas fa-history"></i> Histórico de MRR',
        'Últimos 12 meses fechados — somente contratos ativos',
        'history'
    );

    $meses  = mrr_meses_pt();
    $linhas = [];
    for ($k = 12; $k >= 1; $k--) {
        $ini  = mrr_month_start($k);
        $snap = mrr_snapshot($ini);
        $linhas[] = [
            'rotulo' => $meses[date('m', strtotime($ini))] . '/' . date('Y', strtotime($ini)),
            'curto'  => substr($meses[date('m', strtotime($ini))], 0, 3) . '/' . date('y', strtotime($ini)),
            'snap'   => $snap,
            'rec'    => mrr_received($ini),
        ];
    }

    echo '<div class="panel panel-default mrr-card">';
    echo '<div class="panel-heading"><span><i class="fas fa-chart-area"></i> Evolução (12 meses)</span></div>';
    echo '<div class="panel-body"><div class="mrr-chart"><canvas id="mrrHist12"></canvas></div></div></div>';

    echo '<div class="panel panel-default mrr-card">';
    echo '<div class="panel-heading"><span><i class="fas fa-table"></i> Mês a mês</span></div>';
    echo '<div class="table-responsive"><table class="table table-striped table-condensed mrr-table">';
    echo '<thead><tr><th>Mês</th><th class="text-right">Recebido</th><th class="text-right">Pgtos</th>'
        . '<th class="text-right">MRR</th><th class="text-right">Variação</th>'
        . '<th class="text-right">Contratos</th><th class="text-right">Novo MRR</th>'
        . '<th class="text-right">Cancelado</th></tr></thead><tbody>';

    // O grafico fica em ordem cronologica; a tabela mostra o mes mais recente
    // primeiro, que e como se lê no dia a dia.
    $labels = [];
    $dados  = [];
    $recebido = [];
    foreach ($linhas as $l) {
        $labels[]   = $l['curto'];
        $dados[]    = round($l['snap']['mrr_total'], 2);
        $recebido[] = round($l['rec']['total'], 2);
    }

    $recentes = array_reverse($linhas);
    foreach ($recentes as $i => $l) {
        $s = $l['snap'];
        // Como a lista esta invertida, o mes anterior e o PROXIMO item.
        $velho = isset($recentes[$i + 1]) ? $recentes[$i + 1]['snap']['mrr_total'] : null;
        $var   = $velho === null ? null : $s['mrr_total'] - $velho;

        echo '<tr>';
        echo '<td>' . $l['rotulo'] . '</td>';
        echo '<td class="text-right"><strong style="color:#00a854;">'
            . mrr_money($l['rec']['total']) . '</strong></td>';
        echo '<td class="text-right text-muted">' . $l['rec']['count'] . '</td>';
        echo '<td class="text-right">' . mrr_money($s['mrr_total']) . '</td>';
        echo '<td class="text-right">' . ($var === null
            ? '<span class="text-muted">&mdash;</span>'
            : '<span class="text-' . ($var >= 0 ? 'success' : 'danger') . '">'
              . ($var >= 0 ? '&#9650; ' : '&#9660; ') . mrr_money(abs($var)) . '</span>') . '</td>';
        echo '<td class="text-right">' . $s['count_total'] . '</td>';
        echo '<td class="text-right">' . mrr_money($s['mrr_new']) . '</td>';
        echo '<td class="text-right">' . ($s['mrr_churn'] > 0
            ? '<span class="text-danger">' . mrr_money($s['mrr_churn']) . '</span>'
            : '<span class="text-muted">' . mrr_money(0) . '</span>') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div></div>';
    echo '</div>'; // .mrr

    ?>
    <script>
    (function () {
        if (typeof Chart === 'undefined') { return; }
        var el = document.getElementById('mrrHist12');
        if (!el) { return; }
        new Chart(el, {
            // 'type' obrigatorio tambem aqui, pelo mesmo motivo.
            type: 'bar',
            data: {
                labels: <?php echo json_encode($labels); ?>,
                datasets: [
                    { type: 'bar', label: 'Recebido', data: <?php echo mrr_json_nums($recebido); ?>,
                      backgroundColor: '#00a854', borderRadius: 3, order: 2 },
                    { type: 'line', label: 'MRR', data: <?php echo mrr_json_nums($dados); ?>,
                      borderColor: '#2c7be5', fill: false, tension: .3, borderWidth: 2, order: 1 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } },
                scales: { y: { ticks: { callback: function (v) { return 'R$ ' + v.toLocaleString('pt-BR'); } } } }
            }
        });
    })();
    </script>
    <?php
}

/** Envio de teste + configuracao efetiva + log dos envios do cron. */
function mrr_render_test_whatsapp($vars)
{
    $aviso = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['test_send'])) {
        $fone = trim((string) ($_POST['test_phone'] ?? ''));

        $e164 = mrr_phone_e164($fone);

        if ($e164 === '') {
            $aviso = '<div class="alert alert-warning">Número inválido. Use DDD + número, por exemplo '
                . '<kbd>5581999999999</kbd>.</div>';
        } else {
            // Seguro: api.php so executa o fluxo do cron via linha de comando.
            require_once __DIR__ . '/../api.php';

            $config = [
                'whatsapp_url'          => mrr_setting('whatsapp_url'),
                'whatsapp_instance_id'  => mrr_setting('whatsapp_instance_id'),
                'whatsapp_access_token' => mrr_setting('whatsapp_access_token'),
            ];

            $texto = "*Teste — MRR Analytics*\n\nSe você recebeu esta mensagem, a integração está funcionando.";
            $ok    = mrr_send_whatsapp($e164, $texto, $config);

            $aviso = $ok
                ? '<div class="alert alert-success">Mensagem enviada para <kbd>' . htmlspecialchars($e164)
                  . '</kbd>. Se não chegar em 1 minuto, confira a instância no painel do Zapcel.</div>'
                : '<div class="alert alert-danger">A API recusou o envio. Confira as credenciais abaixo.</div>';
        }
    }

    mrr_admin_header(
        '<i class="fab fa-whatsapp"></i> Testar WhatsApp',
        'Confirme a integração antes de confiar no relatório automático',
        'test_whatsapp'
    );

    echo $aviso;

    $url  = mrr_setting('whatsapp_url');
    $id   = mrr_setting('whatsapp_instance_id');
    $tok  = mrr_setting('whatsapp_access_token');
    $liga = mrr_setting_on('whatsapp_enabled');

    echo '<div class="mrr-row">';

    echo '<div class="panel panel-default mrr-card mrr-overflow">';
    echo '<div class="panel-heading"><span><i class="fas fa-paper-plane"></i> Envio de teste</span></div>';
    echo '<div class="panel-body"><form method="post">';
    echo '<div class="form-group"><label>Número de Teste</label>';
    echo '<input type="text" name="test_phone" class="form-control input-sm" placeholder="5581999999999" value="">';
    echo '<p class="help-block" style="font-size:11px;">Digite o número com código do país e DDD '
        . '(ex: 5581999999999)</p></div>';
    echo '<button type="submit" name="test_send" value="1" class="btn btn-primary">Enviar teste</button>';
    echo '</form></div></div>';

    echo '<div class="panel panel-default mrr-card">';
    echo '<div class="panel-heading"><span><i class="fas fa-cog"></i> Configuração em uso</span></div>';
    echo '<table class="table table-condensed mrr-table" style="margin:0;"><tbody>';
    echo '<tr><td>Relatório automático</td><td class="text-right">'
        . ($liga ? '<span class="label label-success">Ativo</span>' : '<span class="label label-default">Desligado</span>')
        . '</td></tr>';
    echo '<tr><td>Endpoint</td><td class="text-right">' . ($url ? '<kbd>' . htmlspecialchars($url) . '</kbd>'
        : '<span class="label label-danger">não definido</span>') . '</td></tr>';
    echo '<tr><td>Instance ID</td><td class="text-right">' . ($id ? htmlspecialchars($id)
        : '<span class="label label-danger">não definido</span>') . '</td></tr>';
    echo '<tr><td>Token</td><td class="text-right">' . ($tok ? '<span class="label label-success">definido</span>'
        : '<span class="label label-danger">não definido</span>') . '</td></tr>';
    echo '</tbody></table>';
    echo '<div class="panel-footer text-muted" style="font-size:11px;">'
        . 'Configuração exclusiva deste módulo, em Addons &rsaquo; MRR Analytics &rsaquo; Configurar.</div>';
    echo '</div>';

    echo '</div>'; // .mrr-row

    $logs = [];
    try {
        if (Capsule::schema()->hasTable(MRR_TABLE_LOGS)) {
            $logs = Capsule::table(MRR_TABLE_LOGS)->orderBy('id', 'desc')->limit(10)->get();
        }
    } catch (\Throwable $e) {
        $logs = [];
    }

    echo '<div class="panel panel-default mrr-card">';
    echo '<div class="panel-heading"><span><i class="fas fa-inbox"></i> Últimos envios automáticos</span></div>';
    if (count($logs) === 0) {
        echo '<div class="panel-body text-muted">Nenhum relatório enviado ainda.</div>';
    } else {
        echo '<div class="table-responsive"><table class="table table-striped table-condensed mrr-table">';
        echo '<thead><tr><th>Quando</th><th>Tipo</th><th class="text-right">Enviados</th>'
            . '<th class="text-right">Falhas</th></tr></thead><tbody>';
        foreach ($logs as $l) {
            echo '<tr><td>' . htmlspecialchars((string) $l->sent_at) . '</td>'
                . '<td>' . htmlspecialchars((string) $l->report_type) . '</td>'
                . '<td class="text-right">' . (int) $l->total_sent . '</td>'
                . '<td class="text-right">' . ((int) $l->total_failed > 0
                    ? '<span class="text-danger">' . (int) $l->total_failed . '</span>'
                    : '0') . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
    echo '</div>'; // .mrr
}

/**
 * Aba Atualizacoes — mesma logica do modulo hostcelapp: consulta o manifesto
 * publicado pela Hostcel e compara com a versao instalada. Nunca escreve nada
 * sozinho; quem atualiza e o dono do WHMCS.
 */
function mrr_render_update($vars)
{
    mrr_admin_header(
        '<i class="fas fa-cloud-download-alt"></i> Atualizações',
        'Versão instalada e a última publicada',
        'update'
    );

    $latest = mrr_latest();
    $nova   = $latest && version_compare((string) $latest['version'], MRR_VERSION, '>');

    echo '<div class="panel panel-default mrr-card">';
    echo '<div class="panel-heading"><span><i class="fas fa-code-branch"></i> Versão</span></div>';
    echo '<div class="panel-body">';
    echo '<table class="table table-condensed" style="margin:0 0 12px;"><tbody>';
    echo '<tr><td>Instalada</td><td class="text-right"><kbd>v' . MRR_VERSION . '</kbd></td></tr>';
    echo '<tr><td>Última publicada</td><td class="text-right">' . ($latest
        ? '<kbd>v' . htmlspecialchars((string) $latest['version']) . '</kbd>'
          . (!empty($latest['date']) ? ' <span class="text-muted">(' . htmlspecialchars((string) $latest['date']) . ')</span>' : '')
        : '<span class="text-muted">sem resposta do servidor de versões</span>') . '</td></tr>';
    echo '</tbody></table>';

    if (!$latest) {
        echo '<div class="alert alert-info">A verificação automática só funciona depois que o '
            . 'manifesto deste módulo estiver publicado em <kbd>' . htmlspecialchars(MRR_UPDATE_MANIFEST)
            . '</kbd>. Enquanto isso, acompanhe as versões pelo GitHub.</div>';
    }

    if ($nova) {
        echo '<div class="alert alert-warning">Há uma versão nova: <b>v'
            . htmlspecialchars((string) $latest['version']) . '</b>.</div>';
        if (!empty($latest['changelog'])) {
            echo '<p><b>Novidades:</b></p><ul>';
            foreach ((array) $latest['changelog'] as $c) {
                echo '<li>' . htmlspecialchars((string) $c) . '</li>';
            }
            echo '</ul>';
        }
        $dl = !empty($latest['url']) ? (string) $latest['url'] : MRR_DOWNLOAD_URL;
        echo '<a href="' . htmlspecialchars($dl) . '" target="_blank" rel="noopener" class="btn btn-primary">'
            . 'Baixar a v' . htmlspecialchars((string) $latest['version']) . '</a>';
        echo '<p class="help-block">Extraia em <kbd>/modules/addons/mrr/</kbd> substituindo os arquivos '
            . 'e recarregue esta página. Seu histórico é preservado.</p>';
    } else {
        echo '<div class="alert alert-success" style="margin-bottom:10px;">Você está na versão mais recente.</div>';
        echo '<a href="' . MRR_DOWNLOAD_URL . '" target="_blank" rel="noopener" class="btn btn-default">'
            . 'Ver no GitHub</a>';
    }
    echo '</div></div>';
    echo '</div>'; // .mrr
}

/**
 * Aba Modulos gratis — vitrine dos outros modulos que a Hostcel publica.
 * Se nao houver rede ou o manifesto estiver fora, a aba apenas avisa.
 */
function mrr_render_market($vars)
{
    mrr_admin_header(
        '<i class="fas fa-gift"></i> Módulos grátis',
        'Outros módulos que a Hostcel publica para o WHMCS',
        'market'
    );

    echo '<div class="panel panel-default"><div class="panel-body">';
    echo '<h4 class="mrr-tight">Modulos gratuitos da Hostcel</h4>';
    echo '<p class="text-muted mrr-tight">Modulos que publicamos para o WHMCS. '
        . 'Baixe direto do GitHub e instale em <kbd>/modules/addons/</kbd>.</p>';
    echo '</div></div>';

    $market = mrr_marketplace();

    if (!$market) {
        echo '<div class="alert alert-info">Nao foi possivel carregar a lista agora. '
            . 'Tente novamente mais tarde.</div>';
    } else {
        echo '<div class="mrr-market-grid">';
        foreach ($market as $m) {
            $title  = htmlspecialchars((string) ($m['title'] ?? ''));
            $descM  = htmlspecialchars((string) ($m['desc'] ?? ''));
            $cover  = trim((string) ($m['cover'] ?? ''));
            $gh     = trim((string) ($m['github'] ?? ''));
            $badgeM = trim((string) ($m['badge'] ?? ''));
            if ($title === '') {
                continue;
            }
            // Card do painel do proprio tema; a grade e a capa (sempre 2:1)
            // ficam no CSS de layout.
            echo '<div class="panel panel-default mrr-market-card">';
            if ($cover !== '') {
                echo '<img src="' . htmlspecialchars($cover) . '" alt="' . $title . '" width="1200" height="600">';
            }
            echo '<div class="panel-heading"><h3 class="panel-title">' . $title
                . ($badgeM !== '' ? ' <span class="label label-success">' . htmlspecialchars($badgeM) . '</span>' : '')
                . '</h3></div>';
            echo '<div class="panel-body"><p class="text-muted">' . $descM . '</p></div>';
            if ($gh !== '') {
                echo '<div class="panel-footer"><a href="' . htmlspecialchars($gh) . '" target="_blank" '
                    . 'rel="noopener" class="btn btn-primary btn-block">Ver no GitHub</a></div>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    echo '</div>'; // .mrr
}
