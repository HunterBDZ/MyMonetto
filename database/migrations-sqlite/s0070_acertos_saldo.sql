-- Acerto de saldo: a diferença entre o banco e o app, lançada de uma vez para o saldo voltar a bater. Fica fora das
-- categorias: muda o saldo da conta a partir do dia dele, mas não é renda nem gasto, não entra nas Metas, no "Para
-- onde foi", no Entrou nem no Gastou. `referente` é o dia do saldo do banco que ele acerta; `data`, o dia em que ele
-- entra no saldo: o mesmo, ou o 1º dia do primeiro mês aberto, quando o dia do banco é de um mês fechado.
CREATE TABLE acertos_saldo (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  data TEXT NOT NULL CHECK (data IS date(data)),
  referente TEXT NOT NULL CHECK (referente IS date(referente)),
  conta_id INTEGER NOT NULL REFERENCES contas (id),
  valor REAL NOT NULL CHECK (abs(valor) < 1e10),
  descricao TEXT COLLATE AI_CI NOT NULL DEFAULT '' CHECK (length(descricao) <= 255),
  usuario_id INTEGER NOT NULL REFERENCES usuarios (id),
  criado_em TEXT NOT NULL DEFAULT (agora_local()) CHECK (criado_em IS datetime(criado_em)),
  CONSTRAINT chk_acerto_valor CHECK (valor <> 0),
  CONSTRAINT chk_acerto_referente CHECK (referente <= data)
) STRICT;
CREATE INDEX idx_acerto_data ON acertos_saldo (data);
CREATE INDEX fk_acerto_conta ON acertos_saldo (conta_id);
CREATE INDEX fk_acerto_usuario ON acertos_saldo (usuario_id);
CREATE TRIGGER dec_acertos_saldo_valor_i AFTER INSERT ON acertos_saldo WHEN NEW.valor IS NOT dec_fix(NEW.valor, 2) BEGIN UPDATE acertos_saldo SET valor = dec_fix(NEW.valor, 2) WHERE rowid = NEW.rowid; END;
CREATE TRIGGER dec_acertos_saldo_valor_u AFTER UPDATE OF valor ON acertos_saldo WHEN NEW.valor IS NOT dec_fix(NEW.valor, 2) BEGIN UPDATE acertos_saldo SET valor = dec_fix(NEW.valor, 2) WHERE rowid = NEW.rowid; END;
