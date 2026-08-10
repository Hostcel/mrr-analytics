<?php
/**
 * MRR Analytics — motor de calculo.
 *
 * A versao 1.x perguntava "quais servicos estao Active AGORA" e usava essa
 * resposta para todos os meses. Resultado: Jun/26 e Jul/26 davam exatamente
 * R$ 16.147,46 os dois, churn era impossivel de aparecer e o grafico ficava
 * reto. Aqui o mes e reconstruido: um servico entra no mes M se ja existia
 * no fim de M e ainda nao tinha encerrado no comeco de M.
 *
 * @version 2.3.0
 * @author  Hostcel
 */

if (!defined('WHMCS')) {
    die('Acesso direto nao permitido.');
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/config.php';

/**
 * Carrega TODOS os servicos uma unica vez, ja com a data de encerramento
 * resolvida. Nada e filtrado por valor ou por ciclo aqui: quem some da
 * consulta some do relatorio, e foi assim que 7 "Free Account" e os
 * suspensos sumiram na versao anterior.
 */
function mrr_load_services()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $rows = Capsule::table('tblhosting')
        ->leftJoin('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
        ->leftJoin('tblproductgroups', 'tblproducts.gid', '=', 'tblproductgroups.id')
        ->select([
            'tblhosting.id',
            'tblhosting.userid',
            'tblhosting.amount',
            'tblhosting.qty',
            'tblhosting.billingcycle',
            'tblhosting.regdate',
            'tblhosting.termination_date',
            'tblhosting.lastupdate',
            'tblhosting.domainstatus',
            'tblproducts.name as product_name',
            'tblproductgroups.name as group_name',
        ])
        ->whereNotIn('tblhosting.domainstatus', mrr_states_ignored())
        ->get();

    $cycles = mrr_billing_cycles();
    $ended  = mrr_states_ended();
    $out    = [];

    foreach ($rows as $r) {
        $cycle  = (string) $r->billingcycle;
        $months = array_key_exists($cycle, $cycles) ? $cycles[$cycle] : null;

        // Ciclo desconhecido nao vira "mensal" (isso inflava o MRR). Fica
        // marcado para aparecer como aviso no painel, em vez de sumir calado.
        $monthly = 0.0;
        if ($months !== null && $months > 0) {
            $monthly = ((float) $r->amount * max(1, (int) $r->qty)) / $months;
        }

        // Fim do contrato: termination_date quando existe; senao, para quem ja
        // esta encerrado, o ultimo update serve de aproximacao. Quem esta vivo
        // fica sem data.
        $end = null;
        if (!empty($r->termination_date) && $r->termination_date > '0000-00-00') {
            $end = substr((string) $r->termination_date, 0, 10);
        } elseif (in_array($r->domainstatus, $ended, true)) {
            $end = substr((string) $r->lastupdate, 0, 10) ?: null;
        }

        $out[] = (object) [
            'id'            => (int) $r->id,
            'userid'        => (int) $r->userid,
            'amount'        => (float) $r->amount,
            'qty'           => max(1, (int) $r->qty),
            'cycle'         => $cycle,
            'cycle_known'   => $months !== null,
            'recurring'     => $months !== null && $months > 0,
            'monthly'       => $monthly,
            'regdate'       => substr((string) $r->regdate, 0, 10),
            'end_date'      => $end,
            'end_estimated' => $end !== null && (empty($r->termination_date) || $r->termination_date <= '0000-00-00'),
            'status'        => (string) $r->domainstatus,
            'product'       => $r->product_name ?: 'Produto removido',
            'group'         => $r->group_name ?: 'Sem grupo',
        ];
    }

    $cache = $out;

    return $cache;
}

/** Dominios recorrentes, mesma logica de vigencia. */
function mrr_load_domains()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $rows = Capsule::table('tbldomains')
        ->select(['id', 'recurringamount', 'registrationdate', 'expirydate', 'status', 'donotrenew'])
        ->get();

    $out = [];
    foreach ($rows as $r) {
        $end = null;
        if ($r->status !== 'Active' && !empty($r->expirydate) && $r->expirydate > '0000-00-00') {
            $end = substr((string) $r->expirydate, 0, 10);
        }
        $out[] = (object) [
            'id'       => (int) $r->id,
            'monthly'  => ((float) $r->recurringamount) / 12,
            'regdate'  => substr((string) $r->registrationdate, 0, 10),
            'end_date' => $end,
            'status'   => (string) $r->status,
            'renew'    => (int) $r->donotrenew === 0,
        ];
    }

    $cache = $out;

    return $cache;
}

/** O item estava vigente em algum momento do periodo? */
function mrr_was_live($item, $start, $end)
{
    if ($item->regdate > $end) {
        return false;
    }

    return $item->end_date === null || $item->end_date >= $start;
}

/**
 * Retrato fechado de um mes.
 *
 * @param string $periodStart 'Y-m-01'
 */
function mrr_snapshot($periodStart)
{
    $start = date('Y-m-01', strtotime($periodStart));
    $end   = date('Y-m-t', strtotime($periodStart));

    $counted = mrr_states_counted();
    $risk    = mrr_states_risk();

    $res = [
        'period_start'  => $start,
        'period_end'    => $end,
        'label'         => date('m/Y', strtotime($start)),
        'mrr_services'  => 0.0,
        'mrr_domains'   => 0.0,
        'mrr_risk'      => 0.0,   // parte do total que esta suspensa
        'mrr_new'       => 0.0,   // entrou no mes
        'mrr_churn'     => 0.0,   // saiu no mes
        'count_total'   => 0,
        'count_risk'    => 0,
        'count_new'     => 0,
        'count_churn'   => 0,
        'by_product'    => [],
        'by_group'      => [],
        'no_cycle'      => [],    // ciclo desconhecido: nao entra no MRR
        'free'          => 0,     // contratos sem receita recorrente
    ];

    foreach (mrr_load_services() as $s) {
        if (!mrr_was_live($s, $start, $end)) {
            continue;
        }

        // Quem JA ENCERROU conta em todos os meses ate a data de saida — o
        // status de hoje ("Cancelled") nao apaga o passado. So para quem ainda
        // esta vivo e que o status atual decide.
        //
        // A versao anterior desta checagem excluia um servico cancelado em
        // setembro de janeiro a agosto, e o incluia so em setembro: o oposto
        // do certo. Era o que fazia o painel mostrar "crescimento" com saldo
        // de entradas/saidas negativo na mesma tela.
        if ($s->end_date === null && !in_array($s->status, $counted, true)) {
            continue;
        }
        $liveAtEnd = $s->end_date === null || $s->end_date > $end;

        $res['count_total']++;

        if (!$s->cycle_known) {
            $res['no_cycle'][$s->cycle] = ($res['no_cycle'][$s->cycle] ?? 0) + 1;
        }
        if (!$s->recurring) {
            $res['free']++;
        }

        $res['mrr_services'] += $s->monthly;

        if ($liveAtEnd && in_array($s->status, $risk, true)) {
            $res['mrr_risk'] += $s->monthly;
            $res['count_risk']++;
        }

        $res['by_product'][$s->product] = ($res['by_product'][$s->product] ?? 0) + $s->monthly;
        $res['by_group'][$s->group]     = ($res['by_group'][$s->group] ?? 0) + $s->monthly;

        if ($s->regdate >= $start && $s->regdate <= $end) {
            $res['mrr_new'] += $s->monthly;
            $res['count_new']++;
        }
        if ($s->end_date !== null && $s->end_date >= $start && $s->end_date <= $end) {
            $res['mrr_churn'] += $s->monthly;
            $res['count_churn']++;
        }
    }

    foreach (mrr_load_domains() as $d) {
        if (!mrr_was_live($d, $start, $end) || $d->monthly <= 0 || !$d->renew) {
            continue;
        }
        $res['mrr_domains'] += $d->monthly;
        $res['by_group']['Dominios'] = ($res['by_group']['Dominios'] ?? 0) + $d->monthly;
    }

    $res['mrr_total'] = $res['mrr_services'] + $res['mrr_domains'];
    $res['arr']       = $res['mrr_total'] * 12;

    arsort($res['by_product']);
    arsort($res['by_group']);

    return $res;
}

/**
 * Serie dos ultimos N meses, do mais antigo para o mais novo.
 * Inclui o mes corrente (parcial) — marcado para o painel avisar.
 */
function mrr_series($months = 12)
{
    $out = [];
    for ($i = (int) $months - 1; $i >= 0; $i--) {
        $snap = mrr_snapshot(mrr_month_start($i));
        $snap['partial'] = ($i === 0);
        $out[] = $snap;
    }

    return $out;
}

/**
 * Grava o retrato de um mes fechado. Idempotente: recalcular sobrescreve.
 * Sem isso o historico dependeria eternamente da reconstrucao, que nao
 * enxerga reajuste de preco antigo.
 */
function mrr_store_snapshot(array $snap)
{
    if (!Capsule::schema()->hasTable(MRR_TABLE_HISTORY)) {
        return false;
    }

    $data = [
        'period_date'          => $snap['period_start'],
        'total_mrr'            => round($snap['mrr_total'], 2),
        'services_mrr'         => round($snap['mrr_services'], 2),
        'domains_mrr'          => round($snap['mrr_domains'], 2),
        'expansion_mrr'        => round($snap['mrr_new'], 2),
        'churn_mrr'            => round($snap['mrr_churn'], 2),
        'categories_breakdown' => json_encode([
            'by_group'    => $snap['by_group'],
            'by_product'  => $snap['by_product'],
            'count_total' => $snap['count_total'],
            'count_risk'  => $snap['count_risk'],
            'mrr_risk'    => round($snap['mrr_risk'], 2),
        ], JSON_UNESCAPED_UNICODE),
        'calculated_at'        => date('Y-m-d H:i:s'),
    ];

    $exists = Capsule::table(MRR_TABLE_HISTORY)->where('period_date', $snap['period_start'])->first();
    if ($exists) {
        Capsule::table(MRR_TABLE_HISTORY)->where('period_date', $snap['period_start'])->update($data);
    } else {
        Capsule::table(MRR_TABLE_HISTORY)->insert($data);
    }

    return true;
}

/**
 * DINHEIRO RECEBIDO no mes — o que de fato entrou.
 *
 * Fonte: tblaccounts, que e o extrato de pagamentos, somando pela DATA DO
 * PAGAMENTO. Nao usa tblinvoices: fatura emitida, vencida ou cancelada nao e
 * dinheiro. Um pagamento de julho referente a uma fatura de maio conta em
 * julho, que e quando o dinheiro entrou.
 *
 * Nao ha risco de contar duas vezes o credito do cliente: quando ele compra
 * credito (AddFunds) entra uma transacao aqui; quando esse credito depois
 * quita uma fatura, o WHMCS nao gera nova transacao de gateway.
 */
function mrr_received($periodStart)
{
    $ini = date('Y-m-01', strtotime($periodStart));
    $fim = date('Y-m-t', strtotime($periodStart));

    $res = ['total' => 0.0, 'fees' => 0.0, 'count' => 0, 'by_gateway' => []];

    try {
        $linhas = Capsule::table('tblaccounts')
            ->select('gateway')
            ->selectRaw('SUM(amountin) AS entrou, SUM(fees) AS taxas, COUNT(*) AS n')
            ->whereBetween('date', [$ini . ' 00:00:00', $fim . ' 23:59:59'])
            ->groupBy('gateway')
            ->get();

        foreach ($linhas as $l) {
            $v = (float) $l->entrou;
            $res['total'] += $v;
            $res['fees']  += (float) $l->taxas;
            $res['count'] += (int) $l->n;
            $res['by_gateway'][$l->gateway ?: 'sem gateway'] = $v;
        }
        arsort($res['by_gateway']);
    } catch (\Throwable $e) {
        // sem acesso: devolve zerado em vez de derrubar a tela
    }

    return $res;
}

/**
 * DINHEIRO recebido no mes, quebrado por produto.
 *
 * Segue o pagamento ate o item da fatura: transacao -> fatura -> item ->
 * servico -> produto. O que nao e servico (credito, setup, avulso) fica num
 * grupo proprio, em vez de sumir ou ser jogado num produto qualquer.
 *
 * Nao confundir com `by_product` do snapshot, que e MRR de contrato: um
 * produto pode ter contrato ativo e nao ter recebido nada no mes.
 */
function mrr_received_by_product($periodStart)
{
    $ini = date('Y-m-01', strtotime($periodStart));
    $fim = date('Y-m-t', strtotime($periodStart));

    $out = [];

    try {
        $linhas = Capsule::table('tblaccounts as a')
            ->join('tblinvoiceitems as ii', 'ii.invoiceid', '=', 'a.invoiceid')
            ->leftJoin('tblhosting as h', function ($j) {
                $j->on('h.id', '=', 'ii.relid')->where('ii.type', '=', 'Hosting');
            })
            ->leftJoin('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->whereBetween('a.date', [$ini . ' 00:00:00', $fim . ' 23:59:59'])
            ->groupBy('p.name')
            ->selectRaw('p.name AS produto, SUM(ii.amount) AS valor')
            ->get();

        foreach ($linhas as $l) {
            $nome = $l->produto ?: 'Avulso, crédito e setup';
            $out[$nome] = ($out[$nome] ?? 0) + (float) $l->valor;
        }
        arsort($out);
    } catch (\Throwable $e) {
        // sem acesso: devolve vazio em vez de derrubar a tela
    }

    return $out;
}
