-- Adiciona o campo dias_final (janela até D-N) na tabela de controle da Rotina Contínua.
-- Executar no MySQL de controle (banco furk_cycle) ANTES do deploy.
-- Para ambientes novos, o campo já está em 003_furk_dynamic_status_job.sql (CREATE).

ALTER TABLE furk_dynamic_status_job
    ADD COLUMN dias_final INT UNSIGNED NOT NULL DEFAULT 1 AFTER dias_recuo;
