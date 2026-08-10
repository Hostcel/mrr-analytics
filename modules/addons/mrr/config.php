<?php
/**
 * MRR Analytics — constantes, regras de negocio e helpers.
 *
 * Este modulo e AUTOSSUFICIENTE: nao le configuracao de nenhum outro modulo.
 * Quem instalar so o MRR tem que conseguir usar tudo.
 *
 * @version 2.3.0
 * @author  Hostcel
 */

if (!defined('WHMCS')) {
    die('Acesso direto nao permitido.');
}

define('MRR_VERSION', '2.3.0');
define('MRR_TABLE_HISTORY', 'mod_mrr_history');
define('MRR_TABLE_LOGS', 'mod_mrr_logs');

define('MRR_SITE', 'https://www.hostcel.com.br');
define('MRR_LOGO', 'https://www.hostcel.com.br/logo.svg');
define('MRR_DOWNLOAD_URL', 'https://github.com/hostcel/mrr-analytics');
define('MRR_UPDATE_MANIFEST', 'https://hostcel.com.br/downloads/manifesto.php?m=mrr');
define('MRR_MARKET_MANIFEST', 'https://hostcel.com.br/downloads/manifesto.php?m=modulos');

/**
 * Quantos meses cada ciclo de cobranca cobre.
 *
 * Os nomes sao os que o WHMCS grava em tblhosting.billingcycle — inclusive
 * 'Semi-Annually' COM hifen, que ja custou um MRR inflado em 18%.
 * 'Free Account' e 'One Time' entram com 0: nao geram receita recorrente,
 * mas continuam contando como contrato.
 */
function mrr_billing_cycles()
{
    return [
        'Monthly'       => 1,
        'Quarterly'     => 3,
        'Semi-Annually' => 6,
        'Semiannually'  => 6,
        'Annually'      => 12,
        'Biennially'    => 24,
        'Triennially'   => 36,
        'Free Account'  => 0,
        'One Time'      => 0,
    ];
}

/** Status que representam contrato ativo e faturando. */
function mrr_states_active()
{
    return ['Active'];
}

/** Suspenso NAO conta no MRR — "so o que eu recebi". */
function mrr_states_risk()
{
    return ['Suspended'];
}

/** Status que encerram o contrato. */
function mrr_states_ended()
{
    return ['Cancelled', 'Terminated', 'Fraud'];
}

/** Status que ainda nao viraram contrato. */
function mrr_states_ignored()
{
    return ['Pending'];
}

/** Status que somam MRR: SOMENTE ativos. */
function mrr_states_counted()
{
    return mrr_states_active();
}

/**
 * Le uma configuracao DESTE modulo. Sem heranca de outros modulos.
 */
function mrr_setting($chave, $default = '')
{
    static $cache = null;

    if ($cache === null) {
        try {
            $cache = \WHMCS\Database\Capsule::table('tbladdonmodules')
                ->where('module', 'mrr')->pluck('value', 'setting')->toArray();
        } catch (\Throwable $e) {
            $cache = [];
        }
    }

    $v = $cache[$chave] ?? '';

    return ($v === '' || $v === null) ? $default : (string) $v;
}

/** A configuracao esta marcada como ligada? */
function mrr_setting_on($chave)
{
    return in_array(strtolower(mrr_setting($chave)), ['on', '1', 'true', 'yes', 'sim'], true);
}

/**
 * Normaliza um telefone para o formato internacional que a API espera.
 *
 * Sem isso o modulo mandava "81 99326-7690" exatamente como digitado: a API
 * respondia HTTP 200 "queued" e a mensagem nunca chegava, porque falta o DDI.
 * Aceita mascara, espaco, parenteses, +55 e numero sem o 9 antigo.
 *
 * @return string vazio se nao parecer um telefone valido
 */
function mrr_phone_e164($bruto)
{
    $n = preg_replace('/\D+/', '', (string) $bruto);

    if ($n === '') {
        return '';
    }

    // Ja veio com DDI do Brasil e tamanho coerente (55 + DDD + 8 ou 9 digitos).
    if (strpos($n, '55') === 0 && (strlen($n) === 12 || strlen($n) === 13)) {
        return $n;
    }

    // DDD + numero, sem DDI.
    if (strlen($n) === 10 || strlen($n) === 11) {
        return '55' . $n;
    }

    // Outro pais: aceita se tiver tamanho plausivel.
    if (strlen($n) >= 11 && strlen($n) <= 15) {
        return $n;
    }

    return '';
}

/** Formata moeda no padrao brasileiro, sem depender de locale do servidor. */
function mrr_money($v)
{
    return 'R$ ' . number_format((float) $v, 2, ',', '.');
}

/** Primeiro dia do mes, N meses atras (0 = mes corrente). */
function mrr_month_start($monthsAgo = 0)
{
    return (new DateTime('first day of this month 00:00:00'))
        ->modify('-' . (int) $monthsAgo . ' month')
        ->format('Y-m-01');
}

/**
 * Busca um JSON remoto com cache em disco. Falha em silencio.
 *
 * TTL de 1 hora, igual ao hostcelapp. Com 6 horas a vitrine ficava exibindo
 * uma lista velha depois de publicar um modulo novo no manifesto.
 */
function mrr_fetch_json($url, $cacheName, $ttl = 3600)
{
    $cache = sys_get_temp_dir() . '/' . $cacheName;

    if (is_readable($cache) && (time() - (int) filemtime($cache)) < $ttl) {
        $d = json_decode((string) file_get_contents($cache), true);
        if (is_array($d)) {
            return $d;
        }
    }

    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'MRR-Analytics/' . MRR_VERSION,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && $raw) {
            $d = json_decode((string) $raw, true);
            if (is_array($d)) {
                @file_put_contents($cache, $raw);

                return $d;
            }
        }
    } catch (\Throwable $e) {
        // sem rede: segue sem novidade
    }

    return [];
}

/** Ultima versao publicada deste modulo, ou null se nao der para checar. */
function mrr_latest()
{
    $d = mrr_fetch_json(MRR_UPDATE_MANIFEST, 'mrr_latest.json');

    return !empty($d['version']) ? $d : null;
}

/** Outros modulos gratuitos publicados pela Hostcel. */
function mrr_marketplace()
{
    $d = mrr_fetch_json(MRR_MARKET_MANIFEST, 'mrr_market.json');

    return isset($d['modulos']) && is_array($d['modulos']) ? $d['modulos'] : (is_array($d) ? $d : []);
}
