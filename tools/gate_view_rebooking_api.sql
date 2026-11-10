/*
 * View ENXUTA para o sistema Furk Cycle (grid, contagem e consulta por nota do Rebooking).
 * Criar no banco do GATE (SQL Server). NÃO substitui nem altera dbo.Rebooking (outros consumidores).
 *
 * Por quê: dbo.Rebooking junta ~12 tabelas (inclusive wgitiner, 5,2 milhões de linhas, sem usar nenhuma coluna dela no
 * sistema) e leva de 1 s (cache quente) a 50 s (cache frio). Esta view lê só wg_ven + wgfor (+ wgcli para a busca por
 * fan_cli) e responde em ~0,2 s. Mantém EXATAMENTE os mesmos filtros e os mesmos nomes/valores de colunas.
 *
 * Depois de criada: validar com `php tools\comparar_view_gate.php dbo.RebookingApi` e só então configurar
 * GATE_REBOOKING_VIEW=dbo.RebookingApi no .env do servidor (sem novo deploy de código, após o pacote que lê a variável).
 * O "Ver tabela GATE" (colunas estendidas) continua usando dbo.Rebooking (GATE_REBOOKING_EXT_VIEW).
 *
 * ATENÇÃO: se os filtros de dbo.Rebooking mudarem (cliente, fornecedor, janela de dias...), replicar aqui.
 */
CREATE VIEW dbo.RebookingApi
AS
SELECT
    v.nota,
    v.fil_agt,
    v.num_file,
    v.localizador,
    v.tkt_num,
    v.nom_pax,
    f.cod_for,
    f.fan_for,
    v.data_nota,
    v.trecho,
    v.produto,
    tipo_nota = CASE v.forma_nota
        WHEN 'A' THEN 'A VISTA'
        WHEN 'B' THEN 'FATURADO'
        WHEN 'C' THEN 'CARTAO'
        ELSE 'ERRO' END,
    v.tarifa_br,
    v.taxa_1,
    v.taxa_2,
    v.taxa_serv_br,
    v.room_tax,
    v.trans_fee,
    v.receb_cli,
    v.tarifa_br + v.taxa_1 + v.taxa_2 + v.Taxa_serv_br + v.trans_fee AS Total,
    v.vence_cli,
    v.cancel,
    v.faturado,
    CONVERT(VARCHAR, v.data_nota, 23) AS data_tratada,   -- usada na busca da tela
    c.fan_cli                                            -- usada na busca da tela
FROM wg_ven v WITH (NOLOCK)
LEFT JOIN wgfor f WITH (NOLOCK) ON v.cod_prest = f.cod_for
LEFT JOIN wgcli c WITH (NOLOCK) ON v.cod_cli = c.cod_cli AND v.fil_cli = c.fil_agt
WHERE v.data_nota >= DATEADD(DAY, -10, GETDATE())
  AND v.cancel <> '1'
  AND v.produto = '02'
  AND f.cod_for = 'XA'
  AND v.forma_nota = 'B'
  AND v.cod_cad = 'ORIGEM01'
  AND v.cod_cli = '000001';
GO

/*
 * (Opcional, no próprio dbo.Rebooking, para os demais consumidores — testar antes e combinar com o fornecedor do ERP)
 *   1) remover o JOIN "wgdup d" (nenhuma coluna de d é usada no SELECT);
 *   2) no JOIN de wgitiner, incluir a filial para usar o índice IX_Agt_Nota_NumTrecho (fil_agt, nota, num_trecho):
 *        LEFT JOIN wgitiner i WITH (NOLOCK)
 *               ON i.fil_agt = v.fil_agt AND i.nota = v.nota AND i.num_file = v.num_file AND i.num_trecho = 1
 *      (semanticamente mais correto: hoje o JOIN casa a mesma nota de OUTRAS filiais);
 *   3) j (wg_ven_mv_jv): trocar os SUBSTRING por  j.fil_nota = v.fil_agt + v.nota  (sargável, usa IX_FIL_NOTA).
 */
