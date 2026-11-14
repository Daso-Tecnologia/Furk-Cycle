<?php

declare(strict_types=1);

namespace FurkiCycle\Sync;

use FurkiCycle\Config\Env;
use FurkiCycle\Database\MySqlConnection;
use FurkiCycle\Http\ConflictException;
use PDO;
use PDOException;

/**
 * Regra "a Recursiva manda" + exclusão entre cargas (Fase 1, sem tabela nova).
 *
 * - Enquanto a Sincronização Consolidada Contínua Recursiva estiver habilitada
 *   (estado RUNNING ou STOP_REQUESTED, ou ciclo em execução), as OPÇÕES DE CARGA
 *   (leituras manuais, rotinas de Status/Sales, Consolidada e Consolidada Contínua)
 *   são recusadas com HTTP 409 e uma mensagem simples.
 * - Iniciar a Recursiva é recusado se outra rotina/carga estiver em andamento.
 * - As leituras manuais seguram um GET_LOCK durante toda a requisição: duas leituras
 *   manuais nunca rodam juntas (o lock é da sessão e cai sozinho se o PHP morrer).
 *
 * Rebooking, Webhook e telas de leitura NÃO passam por aqui (independentes).
 * Detalhes e fundamentos: docs/ai/analise-concorrencia-rotinas.md.
 */
final class ImportGuard
{
    public const MESSAGE_RECURSIVA = 'Enquanto a Sincronização Consolidada Contínua Recursiva estiver habilitada, não é possível executar esta tarefa.';
    public const MESSAGE_SOLICITE_ADMIN = ' Solicite a um administrador que a interrompa.';

    private const MANUAL_LOCK = 'furk_carga_manual';
    private const RECURSIVA_TABLE = 'furk_sync_rotina';
    private const RECURSIVA_LOCK = 'furk_sync_rotina_routine';

    /**
     * Outras opções que gravam dados (identificadas pela tabela de estado). Cada ponto de
     * entrada pode IGNORAR a(s) sua(s) própria(s) tabela(s) — o serviço da própria rotina já
     * trata "iniciar com ela mesma ativa" — e, no caso da Consolidada Contínua, as rotinas que
     * ela já pausa por desenho (Status e Sales).
     *
     * @var array<int, array{nome: string, tabela: string, estados: array<int, string>, lock: string}>
     */
    private const OUTRAS = [
        ['nome' => 'Status Dinâmicos → Rotina Contínua', 'tabela' => 'furk_dynamic_status_job', 'estados' => ['RUNNING'], 'lock' => 'furk_dynamic_status_routine'],
        ['nome' => 'Sales → Rotina Contínua', 'tabela' => 'furk_sales_job', 'estados' => ['RUNNING'], 'lock' => 'furk_sales_routine'],
        ['nome' => 'Sincronização Consolidada', 'tabela' => 'furk_sync_job', 'estados' => ['QUEUED', 'RUNNING'], 'lock' => 'furk_sync_job_routine'],
        ['nome' => 'Sincronização Consolidada Contínua', 'tabela' => 'furk_sync_continuo_job', 'estados' => ['QUEUED', 'RUNNING'], 'lock' => 'furk_sync_continuo_routine'],
    ];

    private ?PDO $reader = null;
    private ?PDO $lockConnection = null;

    public function __construct(private readonly Env $env)
    {
    }

    /**
     * Para uma OPÇÃO DE CARGA que roda numa requisição web (leitura manual):
     * valida a regra, segura o lock exclusivo da leitura manual até o fim da requisição
     * e revalida a Recursiva (fecha a janela entre a checagem e o lock).
     *
     * @param array<string, mixed> $claims
     * @throws ConflictException
     */
    public function beginManualLoad(array $claims = []): void
    {
        $this->requireCargaPermitida($claims);

        $connection = MySqlConnection::create($this->env);
        $statement = $connection->prepare('SELECT GET_LOCK(:name, 0)');
        $statement->execute(['name' => $this->lock(self::MANUAL_LOCK)]);
        $value = $statement->fetchColumn();
        if ($value === false || $value === null || (int) $value !== 1) {
            throw new ConflictException('Há outra leitura manual em andamento. Aguarde terminar para executar esta tarefa.');
        }
        // Mantém a conexão viva até o fim do script: o lock só cai quando ela fechar.
        $this->lockConnection = $connection;

        if ($this->recursivaAtiva()) {
            $this->releaseManualLock();
            throw new ConflictException($this->mensagemRecursiva($claims));
        }
    }

    /**
     * Limite de período das leituras manuais (padrão 90 dias; `MANUAL_LOAD_MAX_DAYS`): períodos longos pertencem à
     * Consolidada / Consolidada Contínua, que processam em blocos, mostram o andamento e podem ser interrompidas.
     *
     * @throws \RuntimeException mensagem amigável (HTTP 400)
     */
    public function assertPeriodoManual(string $initial, string $final): void
    {
        $max = max(1, min(365, $this->env->int('MANUAL_LOAD_MAX_DAYS', 90)));
        $ini = \DateTimeImmutable::createFromFormat('!Y-m-d', $initial);
        $fim = \DateTimeImmutable::createFromFormat('!Y-m-d', $final);
        if ($ini === false || $fim === false) {
            return; // a validação de datas do próprio serviço devolve a mensagem certa
        }
        $dias = (int) $ini->diff($fim)->days + 1;
        if ($dias > $max) {
            throw new \RuntimeException("Período muito longo para a leitura manual ({$dias} dias; máximo {$max}). Para períodos maiores use a Sincronização Consolidada ou a Consolidada Contínua.");
        }
    }

    /**
     * Registra a leitura manual que acabou de passar pela regra (quem, qual tela, período) e devolve o ponto de
     * conferência para o importador (parada pedida pelo ADMIN ou orçamento de tempo esgotado).
     *
     * @param array<string, mixed> $claims
     */
    public function startManualRun(string $tela, array $claims, string $initial, string $final): ManualRun
    {
        $budget = max(10, min(55, $this->env->int('MANUAL_LOAD_BUDGET_S', 45)));
        $writer = MySqlConnection::create($this->env);
        $registry = new ManualLoadRegistry($writer);
        $userId = (int) ($claims['sub'] ?? 0);
        $name = null;
        try {
            $statement = $writer->prepare('SELECT name FROM users WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $userId]);
            $found = $statement->fetchColumn();
            $name = is_string($found) && $found !== '' ? $found : null;
        } catch (PDOException) {
            // sem nome: o aviso diz só "iniciada às HH:MM"
        }

        $id = $registry->begin($tela, $userId > 0 ? $userId : null, $name, $initial, $final);

        return new ManualRun($registry, $id, $budget, $tela);
    }

    /**
     * Para INICIAR uma rotina/carga com agendamento (Status/Sales Rotina Contínua,
     * Consolidada, Consolidada Contínua): bloqueia se a Recursiva estiver habilitada ou
     * se houver outra carga em andamento.
     *
     * @param array<string, mixed> $claims
     * @param array<int, string> $ignorarTabelas tabelas de estado a ignorar (a própria rotina, etc.)
     * @throws ConflictException
     */
    public function requireCargaPermitida(array $claims = [], array $ignorarTabelas = []): void
    {
        if ($this->recursivaAtiva()) {
            throw new ConflictException($this->mensagemRecursiva($claims));
        }

        $outras = $this->outrasEmAndamento($ignorarTabelas);
        if ($outras !== []) {
            throw new ConflictException($this->mensagemOutras($outras));
        }
    }

    /**
     * Para INICIAR a Recursiva: nenhuma outra rotina/carga pode estar em andamento.
     *
     * @throws ConflictException
     */
    public function requireRecursivaPodeIniciar(): void
    {
        $outras = $this->outrasEmAndamento();
        if ($outras !== []) {
            throw new ConflictException(
                'Não é possível iniciar a Sincronização Consolidada Contínua Recursiva enquanto houver outra sincronização em andamento ('
                . implode(', ', $outras) . '). Aguarde terminar ou interrompa-a antes.',
            );
        }
    }

    /** Recursiva "habilitada": RUNNING, STOP_REQUESTED ou ciclo em execução (lock ocupado). */
    public function recursivaAtiva(): bool
    {
        $row = $this->recursivaRow();
        if ($row !== null && in_array((string) $row['estado'], ['RUNNING', 'STOP_REQUESTED'], true)) {
            return true;
        }

        return $this->lockOcupado(self::RECURSIVA_LOCK);
    }

    /**
     * Nomes das outras opções de carga em andamento: estado que ainda vai rodar
     * (RUNNING/QUEUED) OU ciclo em execução (lock ocupado). STOP_REQUESTED com o lock
     * livre é resíduo inerte e NÃO conta.
     *
     * @param array<int, string> $ignorarTabelas
     * @return array<int, string>
     */
    public function outrasEmAndamento(array $ignorarTabelas = []): array
    {
        $ativas = [];
        foreach (self::OUTRAS as $rotina) {
            if (in_array($rotina['tabela'], $ignorarTabelas, true)) {
                continue;
            }
            if ($this->estadoAtivo($rotina['tabela'], $rotina['estados']) || $this->lockOcupado($rotina['lock'])) {
                $ativas[] = $rotina['nome'];
            }
        }
        if ($this->lockOcupado(self::MANUAL_LOCK)) {
            $ativas[] = 'Leitura manual';
        }

        return $ativas;
    }

    /**
     * Resumo para a interface (faixa de aviso nas telas de carga).
     *
     * @param array<string, mixed> $claims
     * @return array<string, mixed>
     */
    public function status(array $claims = []): array
    {
        $row = $this->recursivaRow();
        $estado = $row !== null ? (string) $row['estado'] : 'IDLE';
        $cicloEmAndamento = $this->lockOcupado(self::RECURSIVA_LOCK);
        $ativa = in_array($estado, ['RUNNING', 'STOP_REQUESTED'], true) || $cicloEmAndamento;
        $outras = $this->outrasEmAndamento();

        $mensagem = null;
        if ($ativa) {
            $mensagem = $this->mensagemRecursiva($claims);
        } elseif ($outras !== []) {
            $mensagem = $this->mensagemOutras($outras);
        }

        // Aviso para a TELA, em português claro (quem, quando, onde). `mensagem` continua sendo o texto curto
        // devolvido no HTTP 409; `aviso` é o que a faixa das telas mostra. `paginas` = telas "donas" das cargas em
        // andamento (cada uma mostra o próprio painel de andamento, sem repetir a faixa).
        $paginas = [];
        foreach ($outras as $nome) {
            $pagina = self::PAGINAS[$nome] ?? null;
            if ($pagina !== null) {
                $paginas[] = $pagina;
            }
        }
        if ($ativa) {
            $paginas[] = self::PAGINAS['Sincronização Consolidada Contínua Recursiva'];
        }

        return [
            'recursiva' => [
                'estado' => $estado,
                'ativa' => $ativa,
                'ciclo_em_andamento' => $cicloEmAndamento,
                'tabela_atual' => $row['tabela_atual'] ?? null,
                'proxima_execucao' => $row['proxima_execucao'] ?? null,
            ],
            'outras_em_andamento' => $outras,
            'cargas_bloqueadas' => $ativa || $outras !== [],
            'mensagem' => $mensagem,
            'aviso' => $ativa ? $mensagem : ($outras !== [] ? $this->avisoOutras($outras, $claims) : null),
            'paginas' => array_values(array_unique($paginas)),
            'leitura_manual' => $this->resumoLeituraManual($outras),
        ];
    }

    /**
     * Para a faixa: há leitura manual em andamento e ela pode ser interrompida (só Sales e Status Dinâmicos)?
     *
     * @param array<int, string> $outras
     * @return array<string, mixed>|null
     */
    private function resumoLeituraManual(array $outras): ?array
    {
        if (!in_array('Leitura manual', $outras, true)) {
            return null;
        }
        $row = $this->leituraManualAtiva();
        if ($row === null) {
            return ['tela' => null, 'interrompivel' => false];
        }

        return [
            'tela' => $row['tela'],
            'usuario' => $row['usuario_nome'],
            'estado' => $row['estado'],
            'interrompivel' => in_array((string) $row['tela'], ['sales', 'status'], true) && (string) $row['estado'] === 'RUNNING',
        ];
    }

    /** Tela de cada rotina (para a faixa não aparecer na própria tela da rotina em andamento). */
    private const PAGINAS = [
        'Status Dinâmicos → Rotina Contínua' => 'rotina-continua.html',
        'Sales → Rotina Contínua' => 'sales-rotina-continua.html',
        'Sincronização Consolidada' => 'sync-consolidada.html',
        'Sincronização Consolidada Contínua' => 'sync-continua.html',
        'Sincronização Consolidada Contínua Recursiva' => 'sync-rotina.html',
    ];

    /**
     * Texto da faixa, sem jargão: o que está rodando, quem iniciou e quando (quando se sabe), em que ponto está
     * e quando a tarefa volta a ficar liberada.
     *
     * @param array<int, string> $outras
     * @param array<string, mixed> $claims
     */
    private function avisoOutras(array $outras, array $claims): string
    {
        $partes = [];
        foreach ($outras as $nome) {
            if ($nome === 'Leitura manual') {
                $partes[] = $this->descricaoLeituraManual();
            } elseif ($nome === 'Sincronização Consolidada') {
                $partes[] = $this->descricaoConsolidada();
            } else {
                $partes[] = "A rotina \"{$nome}\" está em andamento.";
            }
        }

        $texto = implode(' ', $partes) . ' Esta tarefa fica liberada quando terminar.';
        if (array_diff($outras, ['Leitura manual']) !== [] && ($claims['role'] ?? null) === 'ADMIN') {
            $texto .= ' Como administrador, você pode interromper a rotina na tela dela.';
        }

        return $texto;
    }

    private const TELAS_MANUAIS = [
        'sales' => ['Vendas', 'sales-leitura.html'],
        'status' => ['Status Dinâmicos', 'status-dinamicos-leitura.html'],
        'creditos' => ['Créditos', 'creditos-elegiveis.html'],
        'reembolsos' => ['Reembolsos', 'reembolsos-elegiveis.html'],
    ];

    /** @return array<string, mixed>|null a leitura manual em andamento segundo o registro (ou null) */
    private function leituraManualAtiva(): ?array
    {
        return (new ManualLoadRegistry($this->reader()))->active();
    }

    private function descricaoLeituraManual(): string
    {
        $row = $this->leituraManualAtiva();
        if ($row === null) {
            return 'Há uma leitura manual em andamento.';
        }
        $nome = self::TELAS_MANUAIS[(string) $row['tela']][0] ?? 'dados';
        $hora = strtotime((string) $row['iniciado_em']);
        $quem = $row['usuario_nome'] !== null && $row['usuario_nome'] !== '' ? 'por ' . $row['usuario_nome'] . ' ' : '';
        $texto = "Há uma leitura manual de {$nome} em andamento, iniciada {$quem}" . ($hora !== false ? 'às ' . date('H:i', $hora) : 'agora');
        $texto .= ' (período ' . date('d/m', (int) strtotime((string) $row['periodo_inicial'])) . ' a ' . date('d/m', (int) strtotime((string) $row['periodo_final'])) . ')';
        $progresso = json_decode((string) ($row['progresso'] ?? ''), true);
        if (is_array($progresso) && isset($progresso['registros'])) {
            $texto .= ', ' . (int) $progresso['registros'] . ' registros até agora';
        }
        if ((string) $row['estado'] === 'STOP_REQUESTED') {
            $texto .= ', interrompendo';
        }

        return $texto . '.';
    }

    private function descricaoConsolidada(): string
    {
        $texto = 'A Sincronização Consolidada está em andamento';
        try {
            $statement = $this->reader()->query(
                "SELECT created_at, progresso FROM furk_sync_job WHERE estado IN ('QUEUED','RUNNING') ORDER BY id DESC LIMIT 1",
            );
            $row = $statement->fetch();
        } catch (PDOException) {
            return $texto . '.';
        }
        if (!is_array($row)) {
            return $texto . '.';
        }

        $progresso = json_decode((string) ($row['progresso'] ?? ''), true);
        $progresso = is_array($progresso) ? $progresso : [];
        $hora = strtotime((string) $row['created_at']);
        $quem = isset($progresso['iniciado_por']) && is_string($progresso['iniciado_por']) ? $progresso['iniciado_por'] : null;
        if ($hora !== false) {
            $texto = 'A Sincronização Consolidada, iniciada ' . ($quem !== null ? "por {$quem} " : '') . 'às ' . date('H:i', $hora) . ', está em andamento';
        }

        $nomes = ['sales' => 'Vendas', 'creditos' => 'Créditos', 'refunds' => 'Reembolsos', 'bilhetes' => 'Status Dinâmicos'];
        $tabela = isset($progresso['tabela']) && is_string($progresso['tabela']) ? ($nomes[$progresso['tabela']] ?? null) : null;
        if ($tabela !== null) {
            $texto .= ' (' . $tabela . ', passo ' . ((int) ($progresso['passo'] ?? 0) + 1) . ' de ' . (int) ($progresso['passos'] ?? 4) . ')';
        }

        return $texto . '.';
    }

    /** Libera o lock da leitura manual antes do fim do script (uso interno e em testes). */
    public function releaseManualLock(): void
    {
        $this->lockConnection = null;
    }

    /**
     * Mensagem de "outra carga em andamento". Diz como sair da situação: as rotinas têm "Interromper" na
     * própria tela (administradores). A leitura manual termina sozinha ao fim da requisição.
     *
     * @param array<int, string> $outras
     */
    private function mensagemOutras(array $outras): string
    {
        $mensagem = 'Há outra sincronização em andamento (' . implode(', ', $outras) . '). Aguarde terminar para executar esta tarefa.';
        if (array_diff($outras, ['Leitura manual']) !== []) {
            $mensagem .= ' Se for administrador, também pode interrompê-la na tela da própria rotina.';
        }

        return $mensagem;
    }

    /** @param array<string, mixed> $claims */
    private function mensagemRecursiva(array $claims): string
    {
        $role = $claims['role'] ?? null;

        return self::MESSAGE_RECURSIVA . ($role === 'ADMIN' ? '' : self::MESSAGE_SOLICITE_ADMIN);
    }

    /** @return array<string, mixed>|null */
    private function recursivaRow(): ?array
    {
        try {
            $statement = $this->reader()->query(
                'SELECT estado, tabela_atual, proxima_execucao FROM ' . self::RECURSIVA_TABLE . ' ORDER BY id DESC LIMIT 1',
            );
            $row = $statement->fetch();
        } catch (PDOException $error) {
            if ($this->tabelaInexistente($error)) {
                return null;
            }
            throw $error;
        }

        return is_array($row) ? $row : null;
    }

    /**
     * A Consolidada em RUNNING só conta como "em andamento" se tiver batimento recente: um job órfão (o worker caiu
     * e ninguém o retomou) não bloqueia todas as cargas para sempre. Com o lock ocupado ela segue contando
     * (outrasEmAndamento() soma as duas condições). O batimento é gravado pelo PHP (mesmo relógio daqui).
     */
    private const CONSOLIDADA_BATIMENTO_MAX_S = 1800;

    /** @param array<int, string> $estados valores constantes (nunca entrada do usuário) */
    private function estadoAtivo(string $tabela, array $estados): bool
    {
        if ($tabela === 'furk_sync_job') {
            return $this->consolidadaPendente();
        }

        $lista = implode(',', array_map(static fn (string $estado): string => "'" . $estado . "'", $estados));
        try {
            $statement = $this->reader()->query('SELECT 1 FROM ' . $tabela . ' WHERE estado IN (' . $lista . ') LIMIT 1');

            return $statement->fetchColumn() !== false;
        } catch (PDOException $error) {
            if ($this->tabelaInexistente($error)) {
                return false;
            }
            throw $error;
        }
    }

    private function consolidadaPendente(): bool
    {
        try {
            $statement = $this->reader()->query(
                "SELECT estado, heartbeat FROM furk_sync_job WHERE estado IN ('QUEUED','RUNNING') ORDER BY id DESC LIMIT 1",
            );
            $row = $statement->fetch();
        } catch (PDOException $error) {
            if ($this->tabelaInexistente($error)) {
                return false;
            }
            throw $error;
        }

        if (!is_array($row)) {
            return false;
        }
        if ((string) $row['estado'] === 'QUEUED') {
            return true;
        }

        $batimento = $row['heartbeat'] !== null ? strtotime((string) $row['heartbeat']) : false;

        return $batimento === false || (time() - $batimento) <= self::CONSOLIDADA_BATIMENTO_MAX_S;
    }

    private function lockOcupado(string $name): bool
    {
        $statement = $this->reader()->prepare('SELECT IS_FREE_LOCK(:name)');
        $statement->execute(['name' => $this->lock($name)]);
        $value = $statement->fetchColumn();

        // IS_FREE_LOCK: 1 = livre, 0 = em uso, NULL = erro (não conta como em uso).
        return $value !== false && $value !== null && (int) $value === 0;
    }

    /**
     * Nome real do lock. Os nomes de GET_LOCK valem para o SERVIDOR MySQL inteiro (não por
     * banco): um ambiente de TESTE no mesmo servidor da produção definiria LOCK_PREFIX
     * (ex.: "test_") para NUNCA colidir com os locks reais. Em produção a variável não existe.
     */
    private function lock(string $base): string
    {
        return ($this->env->optional('LOCK_PREFIX') ?? '') . $base;
    }

    private function tabelaInexistente(PDOException $error): bool
    {
        return (string) $error->getCode() === '42S02';
    }

    private function reader(): PDO
    {
        return $this->reader ??= MySqlConnection::create($this->env);
    }
}
