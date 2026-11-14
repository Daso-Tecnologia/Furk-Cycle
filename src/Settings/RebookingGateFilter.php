<?php

declare(strict_types=1);

namespace FurkiCycle\Settings;

use RuntimeException;

/**
 * Regras de seleção das notas elegíveis ao Rebooking no GATE (antes fixas na view dbo.Rebooking).
 * Imutável; criada a partir de entrada validada (tela de Configurações) ou dos valores padrão.
 *
 * Os valores PADRÃO reproduzem exatamente os filtros da view original:
 *   data_nota >= hoje - 10 dias · cliente 000001 · fornecedor XA · produto 02 · forma de pagamento B · cadastro ORIGEM01.
 * (O filtro "cancelada <> 1" é fixo e não configurável.)
 */
final class RebookingGateFilter
{
    public const MIN_DIAS = 1;
    public const MAX_DIAS = 180;
    public const MAX_ITENS = 20;

    public const KEY_DIAS = 'rebooking.gate.dias_janela';
    public const KEY_CLIENTES = 'rebooking.gate.clientes';
    public const KEY_FORNECEDORES = 'rebooking.gate.fornecedores';
    public const KEY_PRODUTOS = 'rebooking.gate.produtos';
    public const KEY_FORMAS = 'rebooking.gate.formas_nota';
    public const KEY_CADASTROS = 'rebooking.gate.cadastros';

    /**
     * @param array<int, string> $clientes
     * @param array<int, string> $fornecedores
     * @param array<int, string> $produtos
     * @param array<int, string> $formasNota
     * @param array<int, string> $cadastros
     */
    private function __construct(
        public readonly int $diasJanela,
        public readonly array $clientes,
        public readonly array $fornecedores,
        public readonly array $produtos,
        public readonly array $formasNota,
        public readonly array $cadastros,
    ) {
    }

    public static function defaults(): self
    {
        return new self(10, ['000001'], ['XA'], ['02'], ['B'], ['ORIGEM01']);
    }

    /**
     * Valida a entrada do usuário. Listas aceitam array ou texto separado por vírgula, ponto e vírgula,
     * espaço ou quebra de linha.
     *
     * @param array<string, mixed> $input
     * @throws RuntimeException mensagem amigável (vira HTTP 400)
     */
    public static function fromInput(array $input): self
    {
        return new self(
            self::parseDias($input['diasJanela'] ?? null),
            self::parseLista($input['clientes'] ?? null, 'Clientes'),
            self::parseLista($input['fornecedores'] ?? null, 'Fornecedores (companhias)'),
            self::parseLista($input['produtos'] ?? null, 'Produtos'),
            self::parseLista($input['formasNota'] ?? null, 'Formas de pagamento'),
            self::parseLista($input['cadastros'] ?? null, 'Cadastros (origem)'),
        );
    }

    /**
     * Reconstrói a partir do que está gravado; qualquer valor ausente ou inválido volta ao padrão
     * (edição manual do banco nunca deixa o sistema sem filtro).
     *
     * @param array<string, string> $stored chave => valor
     */
    public static function fromStorage(array $stored): self
    {
        $d = self::defaults();
        $pegar = static function (string $chave, callable $parse, mixed $padrao) use ($stored): mixed {
            if (!array_key_exists($chave, $stored)) {
                return $padrao;
            }
            try {
                return $parse($stored[$chave]);
            } catch (RuntimeException) {
                return $padrao;
            }
        };

        return new self(
            $pegar(self::KEY_DIAS, static fn (string $v): int => self::parseDias($v), $d->diasJanela),
            $pegar(self::KEY_CLIENTES, static fn (string $v): array => self::parseLista($v, 'Clientes'), $d->clientes),
            $pegar(self::KEY_FORNECEDORES, static fn (string $v): array => self::parseLista($v, 'Fornecedores'), $d->fornecedores),
            $pegar(self::KEY_PRODUTOS, static fn (string $v): array => self::parseLista($v, 'Produtos'), $d->produtos),
            $pegar(self::KEY_FORMAS, static fn (string $v): array => self::parseLista($v, 'Formas de pagamento'), $d->formasNota),
            $pegar(self::KEY_CADASTROS, static fn (string $v): array => self::parseLista($v, 'Cadastros'), $d->cadastros),
        );
    }

    /** @return array<string, string> chave => valor, como é gravado */
    public function toStorage(): array
    {
        return [
            self::KEY_DIAS => (string) $this->diasJanela,
            self::KEY_CLIENTES => implode(',', $this->clientes),
            self::KEY_FORNECEDORES => implode(',', $this->fornecedores),
            self::KEY_PRODUTOS => implode(',', $this->produtos),
            self::KEY_FORMAS => implode(',', $this->formasNota),
            self::KEY_CADASTROS => implode(',', $this->cadastros),
        ];
    }

    /** @return array<string, mixed> para a API/tela */
    public function toArray(): array
    {
        return [
            'diasJanela' => $this->diasJanela,
            'clientes' => $this->clientes,
            'fornecedores' => $this->fornecedores,
            'produtos' => $this->produtos,
            'formasNota' => $this->formasNota,
            'cadastros' => $this->cadastros,
        ];
    }

    private static function parseDias(mixed $valor): int
    {
        $texto = is_int($valor) ? (string) $valor : (is_string($valor) ? trim($valor) : '');
        if ($texto === '' || preg_match('/^\d{1,4}$/', $texto) !== 1) {
            throw new RuntimeException('Informe a janela em dias (número inteiro entre ' . self::MIN_DIAS . ' e ' . self::MAX_DIAS . ').');
        }
        $dias = (int) $texto;
        if ($dias < self::MIN_DIAS || $dias > self::MAX_DIAS) {
            throw new RuntimeException('A janela deve ficar entre ' . self::MIN_DIAS . ' e ' . self::MAX_DIAS . ' dias.');
        }

        return $dias;
    }

    /** @return array<int, string> */
    private static function parseLista(mixed $valor, string $nome): array
    {
        if (is_string($valor)) {
            $itens = preg_split('/[\s,;]+/', $valor, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } elseif (is_array($valor)) {
            $itens = [];
            foreach ($valor as $item) {
                if (!is_string($item) && !is_int($item)) {
                    throw new RuntimeException("Valor inválido em {$nome}.");
                }
                foreach (preg_split('/[\s,;]+/', (string) $item, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $parte) {
                    $itens[] = $parte;
                }
            }
        } else {
            $itens = [];
        }

        $limpos = [];
        foreach ($itens as $item) {
            $codigo = strtoupper(trim($item));
            if (preg_match('/^[A-Z0-9._-]{1,20}$/', $codigo) !== 1) {
                throw new RuntimeException("Código inválido em {$nome}: use apenas letras, números, ponto, hífen e sublinhado (até 20 caracteres).");
            }
            $limpos[$codigo] = $codigo;
        }
        $limpos = array_values($limpos);

        if ($limpos === []) {
            throw new RuntimeException("Informe ao menos um código em {$nome}.");
        }
        if (count($limpos) > self::MAX_ITENS) {
            throw new RuntimeException("{$nome}: no máximo " . self::MAX_ITENS . ' códigos.');
        }

        return $limpos;
    }
}
