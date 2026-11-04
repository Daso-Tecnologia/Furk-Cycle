<?php

declare(strict_types=1);

namespace FurkiCycle\BiInsight;

use FurkiCycle\Config\Env;
use FurkiCycle\Database\MySqlConnection;
use PDO;
use RuntimeException;

/**
 * Sincroniza a tabela bi_insight (banco exemplo_bi_insight) a partir das
 * tabelas-fonte do banco de controle (furk_cycle).
 *
 * Regra de correspondência (chave) — HÍBRIDA:
 *   1) Primária (`tkt_num`): o `ticket_number` da origem é comparado ao
 *      `tkt_num` da bi_insight. Quando o `ticket_number` tem mais de 6 posições,
 *      os 3 primeiros caracteres (código de companhia) são ignorados.
 *   2) Complementar (PNR/reemissão): quando a origem é do tipo "locator" — isto
 *      é, o `ticket_number` é igual ao `locator_code` (a Furk devolveu o PNR no
 *      lugar do número do bilhete; observado em ~41% dos itens de um dia), o
 *      valor também é refletido nas linhas de reemissão do mesmo PNR
 *      (`tkt_num = '<locator>-N'` com `localizador = '<locator>'`), que a chave
 *      `tkt_num` isolada não alcança (ex.: caso `GF2ZKL` × `GF2ZKL-1`).
 *
 * Decisão (13/10/2026): ao atualizar a bi_insight pela chave `tkt_num`, TODAS
 * as linhas com o mesmo `tkt_num` são atualizadas com o mesmo valor.
 */
final class BiInsightSyncService
{
    /**
     * @var array<string, array{source: string, target: string, strip_iata?: bool, br_decimal?: bool, null_clears?: bool}>
     */
    private const MAPPING = [
        'furkapi_bilhetes_aereos'    => ['source' => 'ticket_status',          'target' => 'Status_Furkcycle'],
        'furkapi_refunds_elegiveis'  => ['source' => 'estimated_refund_value', 'target' => 'Valor_estimado_reembolso', 'br_decimal' => true],
        'furkapi_creditos_elegiveis' => ['source' => 'estimated_credit',       'target' => 'Valor_estimado_credito'],
        // `originalTicketNumber` = bilhete ORIGINAL, lido de
        // `furkapi_sales.original_ticket_number` (sem o prefixo IATA de 3 chars).
        // Quando `original_ticket_number` é NULL, o bilhete É o próprio original
        // → grava NULL (limpa a auto-referência). Correção de 15/10/2026.
        'furkapi_sales'              => ['source' => 'original_ticket_number', 'target' => 'originalTicketNumber', 'strip_iata' => true, 'null_clears' => true],
    ];

    public function __construct(
        private readonly Env $env,
        private readonly PDO $sourceConnection,
        private readonly ?PDO $targetConnection = null,
    ) {
    }

    /**
     * Atualiza a bi_insight a partir da tabela-fonte indicada.
     * Se informado um período, filtra a origem pela coluna `issue_date`.
     *
     * @return array{source_table: string, source_column: string, target_column: string, keys: int, locator_keys: int, updated_rows: int}
     */
    public function sync(string $sourceTable, ?string $initialIssueDate = null, ?string $finalIssueDate = null): array
    {
        $mapping = self::MAPPING[$sourceTable] ?? throw new RuntimeException("Tabela-fonte não mapeada para bi_insight: {$sourceTable}.");
        $sourceColumn = $mapping['source'];
        $targetColumn = $mapping['target'];

        $target = $this->targetConnection ?? MySqlConnection::createForeign($this->env);

        // 1. Carrega da origem: ticket_number + locator_code -> valor da coluna-fonte.
        //    Nota: NÃO usamos `coluna <> ''` aqui, pois em colunas DECIMAL o
        //    MySQL compara 0.00 = '' como verdadeiro e excluiria valores zero.
        $sql = "SELECT `ticket_number`, `locator_code`, `{$sourceColumn}`
                FROM `{$sourceTable}`
                WHERE `ticket_number` IS NOT NULL
                  AND `ticket_number` <> ''";
        // Mapeamentos com `null_clears` (ex.: originalTicketNumber) precisam
        // processar também as linhas com valor NULL, para LIMPAR o campo.
        if (($mapping['null_clears'] ?? false) !== true) {
            $sql .= " AND `{$sourceColumn}` IS NOT NULL";
        }
        $params = [];
        if ($initialIssueDate !== null && $finalIssueDate !== null) {
            $sql .= " AND `issue_date` BETWEEN :initial AND :final";
            $params['initial'] = $initialIssueDate;
            $params['final'] = $finalIssueDate;
        }
        // Garante que a "última ocorrência vence" seja a mais recente (status atual),
        // já que algumas tabelas-fonte têm várias linhas por ticket_number.
        // A ordenação é ASC porque o mapa é sobrescrito a cada linha: a última
        // linha lida precisa ser a de maior `issue_date` (mais recente).
        $sql .= " ORDER BY `issue_date` ASC";
        $statement = $this->sourceConnection->prepare($sql);
        $statement->execute($params);

        $map = [];
        $locatorMap = [];
        while ($row = $statement->fetch()) {
            $ticketNumber = trim((string) $row['ticket_number']);
            if ($ticketNumber === '') {
                continue;
            }
            $key = $this->stripIata($ticketNumber);
            if ($key === '') {
                continue;
            }
            $raw = $row[$sourceColumn] ?? null;
            $value = $raw === null ? null : (string) $raw;
            // Alguns valores trazem o prefixo IATA (ex.: original_ticket_number
            // "9572300896183" -> "2300896183"); remove no próprio VALOR.
            if ($value !== null && $value !== '' && ($mapping['strip_iata'] ?? false) === true) {
                $value = $this->stripIata($value);
            }
            // Valores monetários vindos em formato brasileiro ("-30,65") são
            // convertidos para o formato ISO ("-30.65") antes de gravar em DECIMAL.
            if ($value !== null && $value !== '' && ($mapping['br_decimal'] ?? false) === true) {
                $value = $this->normalizeBrDecimal($value);
            }
            // Trata string vazia como nulo.
            if ($value === '') {
                $value = null;
            }
            // Nulo é ignorado por padrão. Em mapeamentos com `null_clears`
            // (ex.: originalTicketNumber) o nulo é GRAVADO para limpar o campo.
            if ($value === null && ($mapping['null_clears'] ?? false) !== true) {
                continue;
            }
            // Última ocorrência vence em caso de chave repetida na origem.
            $map[$key] = $value;

            // Registro "tipo locator": a Furk devolveu o PNR no lugar do número do
            // bilhete (`ticket_number` == `locator_code`). Nesse caso o valor é do
            // PNR e precisa alcançar também as linhas de reemissão (`<locator>-N`)
            // do mesmo localizador — que a chave `tkt_num` exata não atinge.
            // (Só propaga valores preenchidos; não propaga nulos.)
            if ($value !== null) {
                $locator = trim((string) ($row['locator_code'] ?? ''));
                if ($locator !== '' && strcasecmp($ticketNumber, $locator) === 0) {
                    $locatorMap[$locator] = $value;
                }
            }
        }

        if ($map === [] && $locatorMap === []) {
            return [
                'source_table' => $sourceTable,
                'source_column' => $sourceColumn,
                'target_column' => $targetColumn,
                'keys' => 0,
                'locator_keys' => 0,
                'updated_rows' => 0,
            ];
        }

        // 2. Prepara tabela temporária na bi_insight para atualização em lote.
        //    match_type: 'T' = casamento exato por tkt_num; 'L' = reemissão do PNR.
        $target->exec('DROP TEMPORARY TABLE IF EXISTS bi_sync_tmp');
        $target->exec('CREATE TEMPORARY TABLE bi_sync_tmp (match_type CHAR(1) NOT NULL, match_key VARCHAR(64) NOT NULL, val VARCHAR(255) NULL, PRIMARY KEY (match_type, match_key))');

        // Monta todas as linhas e insere em lotes (INSERT multi-row). Sem isso,
        // cada chave vira um round-trip até o banco da bi_insight — inviável
        // para volumes grandes (ex.: reparo de status com ~160 mil chaves).
        $rows = [];
        foreach ($map as $key => $value) {
            $rows[] = ['T', (string) $key, $value];
        }
        foreach ($locatorMap as $locator => $value) {
            $rows[] = ['L', (string) $locator, $value];
        }

        $target->beginTransaction();
        try {
            foreach (array_chunk($rows, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '(?, ?, ?)'));
                $parameters = [];
                foreach ($chunk as $row) {
                    $parameters[] = $row[0];
                    $parameters[] = $row[1];
                    $parameters[] = $row[2];
                }
                $insert = $target->prepare('INSERT INTO bi_sync_tmp (match_type, match_key, val) VALUES ' . $placeholders);
                $insert->execute($parameters);
            }

            $updatedByKey = (int) $target->exec(
                "UPDATE `bi_insight` b
                 JOIN `bi_sync_tmp` t ON t.`match_type` = 'T' AND b.`tkt_num` = t.`match_key`
                 SET b.`{$targetColumn}` = t.`val`"
            );

            // Linhas de reemissão do mesmo PNR (ex.: `GF2ZKL-1`): a chave `tkt_num`
            // exata (`GF2ZKL`) não as alcança; casa por `localizador` + prefixo do
            // `tkt_num` (`<locator>-%`). Não sobrepõe a atualização por chave exata.
            $updatedByLocator = (int) $target->exec(
                "UPDATE `bi_insight` b
                 JOIN `bi_sync_tmp` t ON t.`match_type` = 'L'
                   AND b.`localizador` = t.`match_key`
                   AND b.`tkt_num` LIKE CONCAT(t.`match_key`, '-%')
                 SET b.`{$targetColumn}` = t.`val`"
            );

            $target->commit();
        } catch (\Throwable $error) {
            $target->rollBack();
            throw $error;
        } finally {
            $target->exec('DROP TEMPORARY TABLE IF EXISTS bi_sync_tmp');
        }

        return [
            'source_table' => $sourceTable,
            'source_column' => $sourceColumn,
            'target_column' => $targetColumn,
            'keys' => count($map),
            'locator_keys' => count($locatorMap),
            'updated_rows' => $updatedByKey + $updatedByLocator,
        ];
    }

    /**
     * Remove o prefixo IATA de 3 caracteres de um número de bilhete quando ele
     * tiver mais de 6 posições (ex.: "9572300896183" -> "2300896183"). Locators
     * de 6 caracteres permanecem intactos.
     */
    private function stripIata(string $value): string
    {
        return strlen($value) > 6 ? substr($value, 3) : $value;
    }

    /**
     * Converte um valor monetário em formato brasileiro ("-30,65", "1.234,56")
     * para o formato ISO ("-30.65", "1234.56"). Valores que já estão em formato
     * ISO (ex.: "81.27", vindos de colunas DECIMAL) são preservados — caso
     * contrário, o ponto decimal seria removido e o valor multiplicado por 100.
     */
    private function normalizeBrDecimal(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        // Só há formato brasileiro quando existe vírgula decimal.
        // Nesse caso: remove o separador de milhar (.) e troca a vírgula por ponto.
        if (str_contains($value, ',')) {
            return str_replace(['.', ','], ['', '.'], $value);
        }

        // Já está em formato ISO ou é um inteiro: não alterar.
        return $value;
    }
}
