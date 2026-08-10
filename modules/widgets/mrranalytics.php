<?php
/**
 * Widget da home do admin: MRR Analytics.
 * Arquivo: modules/widgets/mrranalytics.php  ->  classe MrranalyticsWidget.
 *
 * IMPORTANTE — este widget NAO tem calculo proprio.
 *
 * A versao anterior repetia a conta dentro deste arquivo e, por isso, carregava
 * todos os erros que ja tinham sido corrigidos no modulo: usava o status de HOJE
 * para julgar meses passados (o MRR nunca variava), barrava `amount > 0` (matava
 * os contratos Free) e ignorava `termination_date` (nao enxergava quem saiu).
 * Resultado: os numeros da home nao batiam com os da tela do modulo.
 *
 * Agora ele chama `modules/addons/mrr/calc.php` — o mesmo motor do painel. Se o
 * modulo nao estiver instalado, o widget avisa em vez de mostrar numero errado.
 *
 * @version 2.3.0
 * @author  Hostcel
 */

use WHMCS\Module\AbstractWidget;

if (!defined('WHMCS')) {
    die('Acesso direto não permitido.');
}

add_hook('AdminHomeWidgets', 1, function () {
    return new MrranalyticsWidget();
});

class MrranalyticsWidget extends AbstractWidget
{
    protected $title = 'MRR Analytics';
    protected $description = 'Dinheiro recebido e receita recorrente';
    protected $weight = 88;
    protected $columns = 4;
    protected $cache = false;

    /** Caminho do motor do modulo. */
    private function motor()
    {
        return __DIR__ . '/../addons/mrr/calc.php';
    }

    public function getData()
    {
        $motor = $this->motor();

        if (!is_readable($motor)) {
            return ['erro' => 'Modulo MRR Analytics nao encontrado em modules/addons/mrr/.'];
        }

        try {
            require_once $motor;

            $m1 = mrr_month_start(1);   // ultimo mes fechado
            $m2 = mrr_month_start(2);

            // Somente DINHEIRO. Nada de projecao de contrato aqui.
            $recM1 = mrr_received($m1);
            $recM2 = mrr_received($m2);

            $var = $recM2['total'] > 0
                ? (($recM1['total'] - $recM2['total']) / $recM2['total']) * 100
                : 0.0;

            $meses = [1 => 'Jan', 2 => 'Fev', 3 => 'Mar', 4 => 'Abr', 5 => 'Mai', 6 => 'Jun',
                      7 => 'Jul', 8 => 'Ago', 9 => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez'];
            $ts = strtotime($m1);

            return [
                'recebido'    => $recM1['total'],
                'pagamentos'  => $recM1['count'],
                'recebidoAnt' => $recM2['total'],
                'pagamentosAnt' => $recM2['count'],
                'var'         => $var,
                'mesLabel'    => $meses[(int) date('n', $ts)] . '/' . date('Y', $ts),
                'mesAnt'      => $meses[(int) date('n', strtotime($m2))] . '/' . date('Y', strtotime($m2)),
            ];
        } catch (\Throwable $e) {
            return ['erro' => $e->getMessage()];
        }
    }

    public function generateOutput($data)
    {
        if (!empty($data['erro'])) {
            return '<div class="widget-content-padded"><div class="alert alert-danger" style="margin:0;">'
                . htmlspecialchars($data['erro']) . '</div></div>';
        }

        $m = function ($v) {
            return 'R$ ' . number_format((float) $v, 2, ',', '.');
        };

        $varTxt = ($data['var'] >= 0 ? '▲ ' : '▼ ') . number_format(abs($data['var']), 1, ',', '.') . '%';
        $varCtx = $data['var'] >= 0 ? 'success' : 'danger';

        // Mesmo layout de sempre. So as CORES sairam do hexadecimal cravado e
        // passaram a usar as classes do Bootstrap do WHMCS (bg-*/text-*), que
        // o tema redefine — inclusive no modo escuro.
        // Ordem cronologica: mes retrasado a esquerda, mes passado a direita.
        $cards = [
            ['title' => 'Recebido (' . $data['mesAnt'] . ')',   'value' => $m($data['recebidoAnt']), 'hint' => $data['pagamentosAnt'] . ' pagamentos', 'ctx' => 'info'],
            ['title' => 'Recebido (' . $data['mesLabel'] . ')', 'value' => $m($data['recebido']),    'hint' => $data['pagamentos'] . ' pagamentos',    'ctx' => 'success'],
            ['title' => 'vs ' . $data['mesAnt'],                'value' => $varTxt,                  'hint' => 'dinheiro recebido',                    'ctx' => $varCtx],
        ];

        $out = '<div class="widget-content-padded"><div style="display:flex; gap:8px; margin-bottom:12px;">';
        foreach ($cards as $c) {
            $out .= '<div class="bg-' . $c['ctx'] . '" style="flex:1; border-radius:8px; padding:12px; text-align:center; border:1px solid rgba(0,0,0,0.05);">'
                . '<div class="text-muted" style="font-size:11px; margin-bottom:6px; font-weight:600; text-transform:uppercase;">' . htmlspecialchars($c['title']) . '</div>'
                . '<div class="text-' . $c['ctx'] . '" style="font-size:18px; font-weight:800; line-height:1.2;">' . $c['value'] . '</div>'
                . '<div class="text-muted" style="font-size:10px; margin-top:4px;">' . $c['hint'] . '</div>'
                . '</div>';
        }
        $out .= '</div>';

        $out .= '<div style="display:flex; gap:8px;">'
            . '<button type="button" onclick="window.location.href=\'addonmodules.php?module=mrr\'" class="btn btn-primary btn-sm" style="flex:1;"><i class="fa fa-line-chart"></i> Abrir MRR Analytics</button>'
            . '</div></div>';

        return $out;
    }
}
