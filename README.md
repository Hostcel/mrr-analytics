# MRR Analytics — módulo WHMCS

Mostra **quanto dinheiro entrou** no seu WHMCS a cada mês, de onde veio e se está
subindo ou caindo. Uso gratuito.

**Versão:** 2.3.0 · **Autor:** [Hostcel](https://www.hostcel.com.br)

---

## O que ele responde

- Quanto eu **recebi** em cada mês — dinheiro que entrou, somado pela data do
  pagamento. Não é valor de fatura: fatura emitida, vencida ou cancelada não é
  dinheiro.
- **Qual produto** trouxe esse dinheiro, e quanto cada um variou de um mês para o
  outro.
- Quanto **entrou de contrato novo** e quanto **saiu em cancelamento** no mês.
- Qual é o **MRR** (receita recorrente mensal) dos contratos ativos, para comparar
  com o que de fato caiu no caixa.

Um widget na home do admin mostra o resumo sem precisar abrir o módulo.

---

## Por que os números não batem com o extrato do gateway

Eles medem coisas diferentes, e as duas leituras estão certas:

- Uma **anuidade** cai inteira no caixa do mês em que foi paga, mas entra no MRR
  como um doze avos.
- Um pagamento **atrasado** conta no mês em que o dinheiro entrou, não no mês da
  fatura.
- **Compra de crédito, setup e itens avulsos** são dinheiro, mas não são receita
  recorrente.

O módulo separa essas coisas em vez de misturar num número só.

---

## Instalação

1. Copie a pasta `modules/` sobre a raiz do seu WHMCS. Ela contém:

   ```
   modules/addons/mrr/          o módulo
   modules/widgets/mrranalytics.php   o widget da home
   ```

   ⚠️ O widget fica **fora** da pasta do módulo. Sem ele, a home mostra números
   desatualizados.

2. No admin, vá em **Addons ▸ Addon Modules** e clique em **Activate** no
   *MRR Analytics*. Na ativação o módulo cria as próprias tabelas e reconstrói
   12 meses de histórico.

3. Em **Configure**, defina quem pode acessar.

Requisitos: WHMCS 8+, PHP 8.1+ e o ionCube Loader.

---

## Relatório por WhatsApp (opcional)

Na aba **Testar WhatsApp**, informe o endpoint e as credenciais da sua API e
envie uma mensagem de teste. O módulo normaliza o número antes de enviar, então
aceita máscara e acrescenta o DDI se faltar.

Para o envio automático, agende:

```
0 * * * * /usr/bin/php /caminho/do/whmcs/modules/addons/mrr/api.php
```

O script confere sozinho a hora e a frequência configuradas; rodar de hora em
hora não gera envio repetido. Ele **só executa por linha de comando** — abrir o
arquivo pelo navegador não dispara nada.

Os destinatários são os administradores ativos com o número nas *notas* no
formato `{5581999999999}`.

---

## O que ele faz no seu WHMCS

Apenas **lê** o banco para calcular: pagamentos, serviços, produtos e domínios.
Não altera faturas, preços, produtos, clientes nem templates, e não acessa senha
nem dado de cartão.

Escreve somente nas próprias tabelas, `mod_mrr_history` e `mod_mrr_logs`.

Nenhum dado é enviado à Hostcel. O relatório vai pela API que você configurar,
para os números que você cadastrar.

---

## Licença

Uso gratuito, código fechado. Veja [LICENSE](LICENSE).

A biblioteca de gráficos (`assets/chart.umd.min.js`) é o
[Chart.js](https://www.chartjs.org/), de terceiros, sob licença MIT, mantida sem
alteração.

---

## Outros módulos gratuitos

<https://www.hostcel.com.br>
