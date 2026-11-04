<?php

declare(strict_types=1);

namespace FurkiCycle\Rebooking;

use FurkiCycle\Config\ConfigurationException;
use FurkiCycle\Config\Env;
use FurkiCycle\Settings\RebookingGateFilter;
use FurkiCycle\Settings\SettingsRepository;
use PDO;
use PDOException;
use RuntimeException;

final class RebookingGateRepository
{
    private const SEARCHABLE_COLUMNS = [
        'nota',
        'localizador',
        'tkt_num',
        'nom_pax',
        'cod_for',
        'fan_for',
        'fan_cli',
        'num_file',
        'data_tratada',
    ];

    private const BASE_COLUMNS = <<<'SQL'
nota, fil_agt, num_file, localizador, tkt_num, nom_pax, cod_for, fan_for,
       data_nota, trecho, produto, tipo_nota, tarifa_br, taxa_1, taxa_2,
       taxa_serv_br, room_tax, trans_fee, receb_cli, Total, vence_cli, cancel, faturado
SQL;

    private const EXTENDED_COLUMNS = <<<'SQL'
nota, fil_agt, num_file, localizador, tkt_num, nom_pax, cod_for, fan_for,
       data_nota, trecho, produto, tipo_nota, tarifa_br, taxa_1, taxa_2,
       taxa_serv_br, room_tax, trans_fee, receb_cli, Total, vence_cli, cancel,
       faturado, tar_prom, cod_pos, pnom_pos, obs_ext3, obs_int2, acomod_tip,
       acomod_cat, mat_pax, dep_pax, fil_cli, cnpj_cli, fan_cli,
       cod_mv_jv, texto_mv_jv, tipo_cli
SQL;

    private ?PDO $connection = null;
    private ?RebookingGateFilter $filter;

    public function __construct(
        private readonly Env $env,
        ?RebookingGateFilter $filter = null,
    ) {
        $this->filter = $filter;
    }

    /**
     * Fonte de leitura: 'interna' (padrão) consulta wg_ven/wgfor/wgcli direto, com as regras editáveis em
     * Configurações; 'view' volta a ler a view do GATE (GATE_REBOOKING_VIEW), como rollback.
     */
    public function usesInternalSource(): bool
    {
        return strtolower((string) $this->env->optional('GATE_REBOOKING_FONTE', 'interna')) !== 'view';
    }

    /** Regras ativas (null quando a fonte é a view, que tem filtros próprios). */
    public function activeFilter(): ?RebookingGateFilter
    {
        if (!$this->usesInternalSource()) {
            return null;
        }

        return $this->filter ??= (new SettingsRepository($this->env))->rebookingGateFilter();
    }

    /**
     * Conexão ao GATE (SQL Server via VPN). Reaproveitada durante toda a requisição:
     * abrir uma conexão por consulta era o principal custo de cada nota enviada.
     */
    public function connect(): PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $host = $this->env->required('GATE_DB_HOST');
        $port = $this->env->optional('GATE_DB_PORT', '1433');
        $name = $this->env->required('GATE_DB_NAME');
        $username = $this->env->required('GATE_DB_USER');
        $password = $this->env->required('GATE_DB_PASSWORD');

        try {
            // LoginTimeout: falha rápido se a VPN/rota estiver fora (antes pendurava ~15 s).
            // SQLSRV_ATTR_QUERY_TIMEOUT: uma consulta lenta devolve erro limpo antes do Timeout 60 s
            // do Apache (que viraria um 504 sem mensagem).
            $this->connection = new PDO(
                "sqlsrv:Server={$host},{$port};Database={$name};LoginTimeout=10",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 50,
                ],
            );
        } catch (PDOException $error) {
            error_log('gate-connect: ' . $error->getMessage());
            throw new RuntimeException('Não foi possível conectar ao Gate (SQL Server). Verifique a VPN e as credenciais.');
        }

        return $this->connection;
    }

    public function count(string $search, ?array $estadoNotas = null, bool $exclude = false): int
    {
        [$where, $params] = $this->whereClause($search, $estadoNotas, $exclude);
        [$source, $sourceParams] = $this->source(false);
        return $this->total($source, $where, $params + $sourceParams);
    }

    /** @return array{rows: array, total: int} */
    public function page(int $page, int $size, string $search, ?array $estadoNotas = null, bool $exclude = false): array
    {
        return $this->fetchPage(false, self::BASE_COLUMNS, $page, $size, $search, $estadoNotas, $exclude);
    }

    /**
     * View das consultas do dia a dia (grid, contagem, por nota): GATE_REBOOKING_VIEW
     * (padrão dbo.Rebooking). Pode apontar para uma view ENXUTA (sem o JOIN com wgitiner etc.),
     * que expõe as colunas BASE + data_tratada e fan_cli (usadas na busca). Ver
     * docs/ai/analise-view-rebooking-gate.md.
     */
    private function baseView(): string
    {
        return $this->viewName('GATE_REBOOKING_VIEW');
    }

    /** View do "Ver tabela GATE" (colunas estendidas): GATE_REBOOKING_EXT_VIEW (padrão dbo.Rebooking). */
    private function extendedView(): string
    {
        return $this->viewName('GATE_REBOOKING_EXT_VIEW');
    }

    private function viewName(string $variable): string
    {
        $name = $this->env->optional($variable, 'dbo.Rebooking') ?? 'dbo.Rebooking';
        // O nome entra no SQL (não pode ser parâmetro): só aceita [schema.]nome simples.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $name) !== 1) {
            throw new ConfigurationException("A variável {$variable} contém um nome de view inválido.");
        }

        return $name;
    }

    /**
     * Retorna o conteúdo bruto da vista do GATE (dbo.Rebooking), sem excluir
     * notas já controladas. É a "fonte direta" usada pelo botão "Ver tabela GATE"
     * para que o operador confirme o que existe no GATE sem depender do estado
     * de envio.
     *
     * @return array{rows: array, total: int}
     */
    public function gatePage(int $page, int $size, string $search): array
    {
        return $this->fetchPage(true, self::EXTENDED_COLUMNS, $page, $size, $search, null, false);
    }

    /** @return ?array<string, mixed> */
    public function findByNota(string $nota): ?array
    {
        [$source, $sourceParams] = $this->source(false);
        $sql = 'SELECT ' . self::BASE_COLUMNS . ' FROM ' . $source
            . ' WHERE nota = CAST(:nota AS VARCHAR(50))'
            . ' ORDER BY data_nota DESC, nota DESC OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY';
        $statement = $this->connect()->prepare($sql);
        $statement->execute(['nota' => $nota] + $sourceParams);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return ?array<string, mixed> */
    public function rowByNota(string $filAgt, string $nota, ?string $numFile = null): ?array
    {
        [$source, $sourceParams] = $this->source(false);
        $sql = 'SELECT ' . self::BASE_COLUMNS . ' FROM ' . $source
            . ' WHERE fil_agt = CAST(:fil_agt AS VARCHAR(50)) AND nota = CAST(:nota AS VARCHAR(50))';
        $params = ['fil_agt' => $filAgt, 'nota' => $nota] + $sourceParams;
        if ($numFile !== null && $numFile !== '') {
            $sql .= ' AND num_file = CAST(:num_file AS VARCHAR(50))';
            $params['num_file'] = $numFile;
        }
        $sql .= ' ORDER BY data_nota DESC, nota DESC';
        $statement = $this->connect()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function itinerary(string $filAgt, string $nota, ?string $numFile): array
    {
        // Parâmetros com CAST(... AS VARCHAR): as colunas do GATE são VARCHAR (collation SQL_Latin1…);
        // um parâmetro NVARCHAR (padrão do driver) faz o SQL Server converter a COLUNA e varrer a
        // tabela inteira (wgitiner tem ~5,2 milhões de linhas). Medido em 26/10/2026: 10,7 s → 0,07 s.
        // O antigo "(:x IS NULL OR coluna = :x)" também impedia o uso do índice; o filtro de
        // num_file agora só entra no SQL quando existe.
        $sql = 'SELECT num_trecho, ori_iti, dest_iti, voo_iti, cia_iti, cl_iti, btar_iti, data, part_iti, dt_cheg, cheg_iti
FROM wgitiner
WHERE fil_agt = CAST(:fil_agt AS VARCHAR(50)) AND nota = CAST(:nota AS VARCHAR(50))';
        $params = ['fil_agt' => $filAgt, 'nota' => $nota];
        if ($numFile !== null) {
            $sql .= ' AND num_file = CAST(:num_file AS VARCHAR(50))';
            $params['num_file'] = $numFile;
        }
        $sql .= ' ORDER BY num_trecho';
        $statement = $this->connect()->prepare($sql);
        $statement->execute($params);

        $segments = [];
        $seen = [];
        foreach ($statement as $row) {
            $number = $this->value($row, 'num_trecho');
            if ($number === null || isset($seen[$number])) {
                continue;
            }
            $seen[$number] = true;
            $segments[] = $row;
        }
        return $segments;
    }

    /** @return array{rows: array, total: int} */
    private function fetchPage(bool $extended, string $columns, int $page, int $size, string $search, ?array $estadoNotas, bool $exclude): array
    {
        [$where, $params] = $this->whereClause($search, $estadoNotas, $exclude);
        [$source, $sourceParams] = $this->source($extended);
        $params += $sourceParams;
        $offset = max(0, ($page - 1) * $size);
        $limit = max(1, $size);

        $sql = "SELECT {$columns} FROM {$source}{$where}"
            . " ORDER BY data_nota DESC, nota DESC OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";

        $statement = $this->connect()->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll();

        return ['rows' => $rows, 'total' => $this->total($source, $where, $params)];
    }

    /** @param array<string, mixed> $params */
    private function total(string $source, string $where, array $params): int
    {
        $statement = $this->connect()->prepare("SELECT COUNT(*) AS total FROM {$source}{$where}");
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    }

    /**
     * Origem das linhas: tabela derivada (fonte interna, com as regras de Configurações) ou a view do GATE.
     * Sempre com alias para aceitar WHERE/ORDER BY externos. Os parâmetros usam o prefixo "s" (não colidem com os de busca "q" e "n").
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function source(bool $extended): array
    {
        if (!$this->usesInternalSource()) {
            return [($extended ? $this->extendedView() : $this->baseView()) . ' AS rb', []];
        }

        $filter = $this->activeFilter();
        if ($filter === null) {
            throw new RuntimeException('Regras do Rebooking indisponíveis.');
        }

        $params = ['sdias' => $filter->diasJanela];
        $conditions = [
            'v.data_nota >= DATEADD(DAY, -CAST(:sdias AS INT), GETDATE())',
            "v.cancel <> '1'",
        ];
        $lists = [
            'sprod' => ['v.produto', $filter->produtos, 10],
            'sfor' => ['f.cod_for', $filter->fornecedores, 20],
            'sforma' => ['v.forma_nota', $filter->formasNota, 5],
            'scad' => ['v.cod_cad', $filter->cadastros, 20],
            'scli' => ['v.cod_cli', $filter->clientes, 20],
        ];
        foreach ($lists as $prefix => [$column, $values, $length]) {
            $placeholders = [];
            foreach ($values as $index => $value) {
                $name = "{$prefix}{$index}";
                $placeholders[] = "CAST(:{$name} AS VARCHAR({$length}))";
                $params[$name] = $value;
            }
            $conditions[] = "{$column} IN (" . implode(', ', $placeholders) . ')';
        }
        $where = implode("\n  AND ", $conditions);

        $select = <<<'SQL'
v.nota, v.fil_agt, v.num_file, v.localizador, v.tkt_num, v.nom_pax, f.cod_for, f.fan_for,
       v.data_nota, v.trecho, v.produto,
       tipo_nota = CASE v.forma_nota WHEN 'A' THEN 'A VISTA' WHEN 'B' THEN 'FATURADO' WHEN 'C' THEN 'CARTAO' ELSE 'ERRO' END,
       v.tarifa_br, v.taxa_1, v.taxa_2, v.taxa_serv_br, v.room_tax, v.trans_fee, v.receb_cli,
       v.tarifa_br + v.taxa_1 + v.taxa_2 + v.Taxa_serv_br + v.trans_fee AS Total,
       v.vence_cli, v.cancel, v.faturado,
       CONVERT(VARCHAR, v.data_nota, 23) AS data_tratada, c.fan_cli
SQL;
        $joins = <<<'SQL'
FROM wg_ven v WITH (NOLOCK)
LEFT JOIN wgfor f WITH (NOLOCK) ON v.cod_prest = f.cod_for
LEFT JOIN wgcli c WITH (NOLOCK) ON v.cod_cli = c.cod_cli AND v.fil_cli = c.fil_agt
SQL;
        if ($extended) {
            $select .= <<<'SQL'
,
       v.tar_prom, v.cod_pos, p.NOM_POS AS pnom_pos, v.obs_ext3, v.obs_int2, v.acomod_tip, v.acomod_cat,
       v.mat_pax, v.dep_pax, v.fil_cli, c.cnpj_cli, j.cod_mv_jv, j.texto_mv_jv, c.tipo_cli
SQL;
            $joins .= <<<'SQL'

LEFT JOIN WgPvenda p WITH (NOLOCK) ON v.cod_pos = p.cod_pos
LEFT JOIN wg_ven_mv_jv j WITH (NOLOCK) ON j.fil_nota = v.fil_agt + v.nota AND j.cod_mv_jv = 'CTRLCL'
SQL;
        }

        return ["(SELECT {$select}\n{$joins}\nWHERE {$where}) AS rb", $params];
    }

    /** @return array{0: string, 1: array<string, mixed>} cláusula WHERE e parâmetros nomeados */
    private function whereClause(string $search, ?array $estadoNotas, bool $exclude): array
    {
        $conditions = [];
        $params = [];

        $search = trim($search);
        if ($search !== '') {
            $like = '%' . $this->escapeLike($search) . '%';
            $parts = [];
            foreach (self::SEARCHABLE_COLUMNS as $index => $column) {
                $name = "q{$index}";
                // O SQL Server não tem caractere de escape padrão: sem ESCAPE, o caractere
                // inserido por escapeLike() viraria texto literal e a busca não acharia nada.
                // Não usar a barra invertida: o analisador de parâmetros do PDO a trata como
                // escape dentro de aspas e "engole" os placeholders seguintes (HY093).
                $parts[] = "{$column} LIKE CAST(:{$name} AS VARCHAR(200)) ESCAPE '!'";
                $params[$name] = $like;
            }
            $conditions[] = '(' . implode(' OR ', $parts) . ')';
        }

        if ($estadoNotas !== null && $estadoNotas !== []) {
            $operator = $exclude ? 'NOT IN' : 'IN';
            $placeholders = [];
            foreach ($estadoNotas as $index => $nota) {
                $name = "n{$index}";
                $placeholders[] = "CAST(:{$name} AS VARCHAR(50))";
                $params[$name] = $nota;
            }
            $conditions[] = "nota {$operator} (" . implode(', ', $placeholders) . ')';
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        return [$where, $params];
    }

    private function escapeLike(string $value): string
    {
        $escaped = '';
        foreach (str_split($value) as $character) {
            $escaped .= in_array($character, ['[', '%', '_', '!'], true)
                ? '!' . $character
                : $character;
        }
        return $escaped;
    }

    private function value(array $row, string $field): ?string
    {
        $item = $row[$field] ?? null;
        if (!is_string($item) && !is_numeric($item)) {
            return null;
        }
        $text = trim((string) $item);
        return $text === '' || $text === "\0" ? null : $text;
    }
}
