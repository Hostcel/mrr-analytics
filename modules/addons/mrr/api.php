<?php
/**
 * MRR Analytics - API de Relatórios via WhatsApp
 * Script para ser executado via cron para envio automático de relatórios
 * 
 * Uso: php /path/to/modules/addons/mrr/api.php
 */

use WHMCS\Database\Capsule;

// Inicializar WHMCS
$whmcsPath = dirname(dirname(dirname(__DIR__)));
require_once $whmcsPath . '/init.php';

// Mesmo motor do painel. Ter dois calculos era o que fazia o relatorio do
// WhatsApp divergir do dashboard.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/calc.php';

date_default_timezone_set('America/Recife');

/**
 * Função para extrair telefones das notas
 */
function mrr_extract_phones_from_notes($notes)
{
    $phones = [];
    if (preg_match_all('/\{(\d{10,16})\}/', $notes, $matches)) {
        foreach ($matches[1] as $match) {
            if (strlen($match) >= 10 && strlen($match) <= 16) {
                $phones[] = $match;
            }
        }
    }
    return $phones;
}

/**
 * Função para obter telefones dos administradores
 */
function mrr_get_admin_phones($adminPhoneField)
{
    $phones = [];
    try {
        $admins = Capsule::table('tbladmins')
            ->select('*')
            ->where('disabled', 0)
            ->get();

        foreach ($admins as $admin) {
            // Campo direto (ex: mobilenumber)
            if ($adminPhoneField && isset($admin->{$adminPhoneField})) {
                $raw = trim((string)$admin->{$adminPhoneField});
                if ($raw !== '') {
                    $num = preg_replace('/\D+/', '', $raw);
                    if (strlen($num) >= 10 && strlen($num) <= 16) {
                        $phones[] = $num;
                    }
                }
            }
            
            // Telefones nas notas no formato {5581999999999}
            if (!empty($admin->notes)) {
                $phones = array_merge($phones, mrr_extract_phones_from_notes($admin->notes));
            }
        }
    } catch (Exception $e) {
        mrr_log("Erro ao buscar telefones de admins: " . $e->getMessage());
    }
    
    return array_values(array_unique($phones));
}

/**
 * Função de envio de mensagem via WhatsApp
 */
function mrr_send_whatsapp($numero, $mensagem, $config)
{
    // Normaliza ANTES de enviar. Sem isto, "81 99326-7690" ia cru para a API,
    // que respondia HTTP 200 "queued" e nunca entregava — faltava o DDI 55.
    $original = $numero;
    $numero   = mrr_phone_e164($numero);

    if ($numero === '') {
        mrr_log("Numero invalido, envio abortado: {$original}");

        return false;
    }

    $dados = http_build_query([
        'number' => $numero,
        'type' => 'text',
        'message' => $mensagem,
        'instance_id' => $config['whatsapp_instance_id'],
        'access_token' => $config['whatsapp_access_token']
    ]);

    $ch = curl_init($config['whatsapp_url']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $dados);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // HTTP 200 nao basta: a API devolve 200 mesmo recusando o envio.
    $corpo = json_decode((string) $resp, true);
    $ok    = $http_code === 200
        && (!is_array($corpo) || (($corpo['status'] ?? '') !== 'error'));

    $comoFoi = $original === $numero ? $numero : "{$original} -> {$numero}";
    mrr_log("Envio {$comoFoi} | HTTP: {$http_code} | " . ($err ? "Erro: {$err}" : ($ok ? 'OK' : 'RECUSADO')) . " | Resposta: {$resp}");

    return $ok;
}

/**
 * Função de log
 */
function mrr_log($message)
{
    $logFile = __DIR__ . '/log_whatsapp.txt';
    $timestamp = date('d/m/Y H:i:s');
    file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND);
    echo "[{$timestamp}] {$message}\n";
}

/**
 * Gera mensagem de relatório
 */
function mrr_generate_report_message($frequency)
{
    // Mesmo motor do painel (calc.php). O mes fechado tambem e gravado como
    // retrato, para o historico crescer sozinho a cada execucao do cron.
    $mrrM1 = mrr_snapshot(mrr_month_start(1));
    $mrrM2 = mrr_snapshot(mrr_month_start(2));
    mrr_store_snapshot($mrrM1);

    $totalMRR_M1 = $mrrM1['mrr_total'];
    $totalMRR_M2 = $mrrM2['mrr_total'];
    $servicesMRR_M1 = $mrrM1['by_group'];
    
    // Calcular variação
    $variationPercentage = 0;
    $variationAbsolute = $totalMRR_M1 - $totalMRR_M2;
    
    if ($totalMRR_M2 > 0) {
        $variationPercentage = (($totalMRR_M1 - $totalMRR_M2) / $totalMRR_M2) * 100;
    }
    
    $variationIcon = $variationPercentage >= 0 ? '📈' : '📉';
    $variationText = $variationPercentage >= 0 ? 'Crescimento' : 'Queda';
    
    // Formatação
    $totalMRR_M1_Formatted = 'R$ ' . number_format($totalMRR_M1, 2, ',', '.');
    $totalMRR_M2_Formatted = 'R$ ' . number_format($totalMRR_M2, 2, ',', '.');
    $variationPercentageFormatted = number_format(abs($variationPercentage), 2, ',', '.');
    $variationAbsoluteFormatted = 'R$ ' . number_format(abs($variationAbsolute), 2, ',', '.');
    $totalARR_Formatted = 'R$ ' . number_format($totalMRR_M1 * 12, 2, ',', '.');
    
    // Nomes dos meses
    $monthNames = [
        '01' => 'Janeiro', '02' => 'Fevereiro', '03' => 'Março',
        '04' => 'Abril', '05' => 'Maio', '06' => 'Junho',
        '07' => 'Julho', '08' => 'Agosto', '09' => 'Setembro',
        '10' => 'Outubro', '11' => 'Novembro', '12' => 'Dezembro'
    ];
    
    $m1Start = $mrrM1['period_start'];
    $m2Start = $mrrM2['period_start'];
    $m1Name  = $monthNames[date('m', strtotime($m1Start))] . '/' . date('Y', strtotime($m1Start));
    $m2Name  = $monthNames[date('m', strtotime($m2Start))] . '/' . date('Y', strtotime($m2Start));
    
    // Montar mensagem
    $frequencyLabel = [
        'daily' => 'Diário',
        'weekly' => 'Semanal',
        'monthly' => 'Mensal'
    ];
    
    $message = "📊 *RELATÓRIO MRR " . strtoupper($frequencyLabel[$frequency]) . "*\n\n";
    $message .= "🗓️ *Comparação de Meses Completos*\n";
    $message .= "━━━━━━━━━━━━━━━━━━━━━━\n\n";
    
    $message .= "📅 *{$m2Name}* (M-2)\n";
    $message .= "MRR: *{$totalMRR_M2_Formatted}*\n\n";
    
    $message .= "{$variationIcon} *{$variationText}*: *{$variationPercentageFormatted}%*\n";
    $message .= "Diferença: {$variationAbsoluteFormatted}\n\n";
    
    $message .= "📅 *{$m1Name}* (M-1)\n";
    $message .= "MRR: *{$totalMRR_M1_Formatted}*\n";
    $message .= "ARR Projetado: *{$totalARR_Formatted}*\n\n";
    
    $message .= "━━━━━━━━━━━━━━━━━━━━━━\n";
    $message .= "📈 *Top Categorias ({$m1Name})*\n\n";
    
    // Top 5 categorias
    arsort($servicesMRR_M1);
    $topCategories = array_slice($servicesMRR_M1, 0, 5, true);
    $position = 1;
    
    foreach ($topCategories as $category => $mrr) {
        $percentage = $totalMRR_M1 > 0 ? round(($mrr / $totalMRR_M1) * 100, 1) : 0;
        $categoryMRRFormatted = 'R$ ' . number_format($mrr, 2, ',', '.');
        $message .= "{$position}. *{$category}*\n";
        $message .= "   {$categoryMRRFormatted} ({$percentage}%)\n\n";
        $position++;
    }
    
    $message .= "━━━━━━━━━━━━━━━━━━━━━━\n";
    $message .= "✅ Dados 100% precisos baseados em meses completos\n";
    $message .= "🔗 Acesse o WHMCS para visualizar o dashboard completo";
    
    return $message;
}

/**
 * Verifica se deve enviar relatório baseado na frequência
 */
function mrr_should_send_report($frequency, $hour)
{
    $currentHour = (int)date('H');
    $currentDay = (int)date('d');
    $currentWeekday = (int)date('N'); // 1 = segunda, 7 = domingo
    
    // Verifica se está no horário correto
    if ($currentHour != $hour) {
        return false;
    }
    
    switch ($frequency) {
        case 'daily':
            // Envia todo dia no horário configurado
            return true;
            
        case 'weekly':
            // Envia toda segunda-feira (1) no horário configurado
            return $currentWeekday == 1;
            
        case 'monthly':
            // Envia no primeiro dia do mês no horário configurado
            return $currentDay == 1;
            
        default:
            return false;
    }
}

// ============================================================================
// EXECUÇÃO PRINCIPAL — SOMENTE VIA CRON (linha de comando)
// ============================================================================

// Sem esta trava o arquivo disparava relatorio para todos os admins em dois
// casos: (1) o painel faz require deste arquivo na aba "Testar WhatsApp", e o
// fluxo abaixo rodava junto, terminando em exit(0) e matando a pagina;
// (2) qualquer pessoa abrindo /modules/addons/mrr/api.php no navegador.
// Pelo cron (php CLI) nada muda.
if (PHP_SAPI !== 'cli') {
    return;
}

mrr_log("========================================");
mrr_log("Iniciando verificação de relatórios MRR");

// Carregar configurações — exclusivas deste módulo, sem herdar de outros.
$settings = Capsule::table('tbladdonmodules')
    ->where('module', 'mrr')
    ->pluck('value', 'setting')
    ->toArray();

// Somente a configuracao DESTE modulo — sem herdar de outros.
$config = [
    'whatsapp_url'          => mrr_setting('whatsapp_url'),
    'whatsapp_instance_id'  => mrr_setting('whatsapp_instance_id'),
    'whatsapp_access_token' => mrr_setting('whatsapp_access_token'),
    'whatsapp_enabled'      => mrr_setting_on('whatsapp_enabled'),
    'report_frequency'      => $settings['report_frequency'] ?? 'monthly',
    'report_hour'           => (int) ($settings['report_hour'] ?? 9),
    // telefone vem das notes {numero}; tbladmins não tem coluna de celular.
    'admin_phone_field'     => $settings['admin_phone_field'] ?? '',
];

// Verificar se está habilitado
if (!$config['whatsapp_enabled']) {
    mrr_log("Relatórios via WhatsApp desabilitados");
    exit;
}

// Verificar se deve enviar baseado na frequência
if (!mrr_should_send_report($config['report_frequency'], $config['report_hour'])) {
    mrr_log("Não é hora de enviar relatório. Frequência: {$config['report_frequency']}, Hora configurada: {$config['report_hour']}h, Hora atual: " . date('H') . "h");
    exit;
}

mrr_log("Condições atendidas. Iniciando envio de relatório {$config['report_frequency']}");

// Obter números de administradores
$adminPhones = mrr_get_admin_phones($config['admin_phone_field']);

if (empty($adminPhones)) {
    mrr_log("ERRO: Nenhum número de administrador configurado");
    exit;
}

mrr_log("Números de administradores encontrados: " . count($adminPhones));

// Anti-duplicação: já enviou este tipo de relatório nesta hora? (cron roda de hora em hora)
try {
    $jaEnviou = Capsule::table('mod_mrr_logs')
        ->where('report_type', $config['report_frequency'])
        ->where('sent_at', '>=', date('Y-m-d H:00:00'))
        ->exists();
    if ($jaEnviou) {
        mrr_log('Relatório já enviado nesta hora. Ignorando duplicata.');
        exit;
    }
} catch (\Throwable $e) { /* tabela pode não existir ainda — segue */ }

// Gerar mensagem do relatório
try {
    $message = mrr_generate_report_message($config['report_frequency']);
} catch (\Throwable $e) {
    mrr_log('ERRO ao gerar relatório: ' . $e->getMessage());
    exit(1);
}

// Enviar para cada administrador
$totalSent = 0;
$totalFailed = 0;
$sentPhones = [];

foreach ($adminPhones as $phone) {
    mrr_log("Enviando para: {$phone}");
    
    if (mrr_send_whatsapp($phone, $message, $config)) {
        $totalSent++;
        $sentPhones[] = $phone;
        mrr_log("✓ Enviado com sucesso para {$phone}");
    } else {
        $totalFailed++;
        mrr_log("✗ Falha ao enviar para {$phone}");
    }
    
    // Pequeno delay entre envios
    sleep(1);
}

// Registrar log no banco
try {
    Capsule::table('mod_mrr_logs')->insert([
        'report_type' => $config['report_frequency'],
        'phone_numbers' => json_encode($sentPhones),
        'message_content' => $message,
        'total_sent' => $totalSent,
        'total_failed' => $totalFailed,
        'sent_at' => date('Y-m-d H:i:s'),
        'response_log' => "Enviados: {$totalSent}, Falhas: {$totalFailed}"
    ]);
    
    mrr_log("Log registrado no banco de dados");
} catch (Exception $e) {
    mrr_log("Erro ao registrar log: " . $e->getMessage());
}

// Relatório final
mrr_log("========================================");
mrr_log("RESUMO DO ENVIO:");
mrr_log("Total de envios bem-sucedidos: {$totalSent}");
mrr_log("Total de falhas: {$totalFailed}");
mrr_log("========================================");

exit(0);
