-- Configurações editáveis pela tela "Configurações" (somente ADMIN altera) + histórico de alterações.
-- Executar no MySQL de controle ANTES de publicar o código que usa a tela. É SEGURO publicar o código antes:
-- sem a tabela, o sistema usa os valores PADRÃO (os mesmos filtros que estavam fixos na view do GATE).
-- InnoDB é obrigatório (alterações gravam valor + histórico na mesma transação).
--
-- Chaves usadas hoje (prefixo rebooking.gate.): dias_janela, clientes, fornecedores, produtos, formas_nota, cadastros.
-- Listas são gravadas separadas por vírgula (ex.: "AD,G3").

CREATE TABLE IF NOT EXISTS furk_configuracao (
    chave VARCHAR(64) NOT NULL,
    valor VARCHAR(255) NOT NULL,
    atualizado_por BIGINT UNSIGNED NULL,
    atualizado_por_nome VARCHAR(150) NULL,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS furk_configuracao_historico (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    chave VARCHAR(64) NOT NULL,
    valor_anterior VARCHAR(255) NULL,
    valor_novo VARCHAR(255) NOT NULL,
    alterado_por BIGINT UNSIGNED NULL,
    alterado_por_nome VARCHAR(150) NULL,
    alterado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_furk_configuracao_historico_chave (chave, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
