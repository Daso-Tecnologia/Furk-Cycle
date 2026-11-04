<?php

declare(strict_types=1);

namespace FurkiCycle\Sync;

/**
 * Uma leitura manual em andamento: dá ao importador um ponto de conferência ("checkpoint") entre páginas/partes.
 * Devolve null para continuar, ou o motivo da parada: 'interrompido' (ADMIN pediu) ou 'tempo' (estourou o orçamento
 * de tempo da leitura manual — a tela do navegador desiste aos 60 s, então a leitura para limpa antes disso).
 */
final class ManualRun
{
    private float $startedAt;

    public function __construct(
        private readonly ManualLoadRegistry $registry,
        private readonly ?int $id,
        private readonly int $budgetSeconds,
        private readonly string $tela,
        ?float $startedAt = null,
    ) {
        $this->startedAt = $startedAt ?? microtime(true);
    }

    /**
     * @param array<string, mixed> $info {pagina, registros, ate}
     * @return 'interrompido'|'tempo'|null
     */
    public function checkpoint(array $info): ?string
    {
        $decorrido = microtime(true) - $this->startedAt;
        if ($this->id !== null) {
            $this->registry->touch($this->id, $info + ['tela' => $this->tela, 'segundos' => (int) round($decorrido)]);
            if ($this->registry->stopRequested($this->id)) {
                return 'interrompido';
            }
        }

        return $decorrido >= $this->budgetSeconds ? 'tempo' : null;
    }

    /** @param array<string, mixed> $summary */
    public function finish(string $estado, array $summary = []): void
    {
        if ($this->id !== null) {
            $this->registry->finish($this->id, $estado, $summary);
        }
    }

    public function id(): ?int
    {
        return $this->id;
    }
}
