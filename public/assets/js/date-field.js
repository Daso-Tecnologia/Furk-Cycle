/*
 * Campo de data como TEXTO com máscara dd/mm/aaaa (+ botão de calendário), aplicado SOZINHO a todo <input type="date">
 * editável da página. Motivo: o campo de data nativo divide a data em segmentos (dia/mês/ano). Ao clicar no segmento
 * errado, o que se digita vai para o ano ("0025", "0002") e o calendário trava. Com texto, digitar "30032025" vira
 * "30/03/2025" e colar "30/03/2025" funciona.
 *
 * COMPATIBILIDADE: para o resto do código da tela nada muda. O campo continua devolvendo e aceitando `.value` em
 * AAAA-MM-DD (ISO); o texto digitado (dd/mm/aaaa) fica por dentro. Data incompleta ou inexistente => `.value` = ''.
 * Carregar este script ANTES do script da tela (ele precisa estar ativo quando a tela grava os valores padrão).
 */
(() => {
  const MIN_ISO = '2020-01-01';
  const MSG_INVALIDA = 'Data inválida: use dia/mês/ano, com 4 dígitos no ano (ex.: 29/03/2025).';
  const MSG_MINIMA = 'Informe uma data a partir de 01/01/2020.';

  /** Só dígitos, no máximo 8, com as barras nos lugares certos: "30032025" -> "30/03/2025". */
  const maskBr = (raw) => {
    const d = String(raw).replace(/[^0-9]/g, '').slice(0, 8);
    let out = d.slice(0, 2);
    if (d.length > 2) out += '/' + d.slice(2, 4);
    if (d.length > 4) out += '/' + d.slice(4, 8);
    return out;
  };

  /** "30/03/2025" -> "2025-03-30"; '' se o texto não for uma data real (ex.: 31/02/2025, 30/03/0025). */
  const brToIso = (br) => {
    const m = /^([0-9]{2})\/([0-9]{2})\/([0-9]{4})$/.exec(String(br).trim());
    if (!m) return '';
    const day = Number(m[1]);
    const month = Number(m[2]);
    const year = Number(m[3]);
    const probe = new Date(year, month - 1, day);
    if (probe.getFullYear() !== year || probe.getMonth() !== month - 1 || probe.getDate() !== day) return '';
    return `${m[3]}-${m[2]}-${m[1]}`;
  };

  /** "2025-03-30" -> "30/03/2025"; '' se inválido. */
  const isoToBr = (iso) => {
    const m = /^([0-9]{4})-([0-9]{2})-([0-9]{2})$/.exec(String(iso || ''));
    return m ? `${m[3]}/${m[2]}/${m[1]}` : '';
  };

  const ISO_RE = /^[0-9]{4}-[0-9]{2}-[0-9]{2}$/;
  const RAW = Symbol('dateFieldRaw');

  /** Leitura/gravação do TEXTO bruto do campo (o `.value` público passa a ser o ISO). */
  const rawOf = (input) => input[RAW];

  function attach(input) {
    if (input[RAW]) return input; // já aplicado

    // Acesso ao valor nativo (texto) mesmo depois de sobrescrever `.value`.
    const proto = typeof HTMLInputElement !== 'undefined' ? HTMLInputElement.prototype : null;
    const native = proto ? Object.getOwnPropertyDescriptor(proto, 'value') : null;
    let store = ''; // só usado fora do navegador (testes)
    const rawGet = native ? () => native.get.call(input) : () => store;
    const rawSet = native ? (v) => native.set.call(input, v) : (v) => { store = String(v); };

    const existing = rawGet() || (native ? '' : input.value) || ''; // valor ISO que o campo já tinha (type=date)
    input.type = 'text';
    input.inputMode = 'numeric';
    input.maxLength = 10;
    input.placeholder = 'dd/mm/aaaa';
    input.autocomplete = 'off';
    input.removeAttribute('min');
    input.removeAttribute('max');
    rawSet(ISO_RE.test(existing) ? isoToBr(existing) : maskBr(existing));
    input[RAW] = { get: rawGet, set: rawSet };

    // `.value` continua sendo ISO para o restante do código da tela.
    Object.defineProperty(input, 'value', {
      configurable: true,
      get() { return brToIso(rawGet()); },
      set(v) {
        const s = String(v ?? '');
        rawSet(ISO_RE.test(s) ? isoToBr(s) : maskBr(s));
        refreshValidity(input);
      },
    });

    input.addEventListener('input', () => {
      const masked = maskBr(rawGet());
      if (masked !== rawGet()) rawSet(masked);
      refreshValidity(input);
    });
    input.addEventListener('blur', () => refreshValidity(input, true));

    const wrap = document.createElement('div');
    wrap.className = 'date-field';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'date-picker-btn';
    button.title = 'Abrir calendário';
    button.setAttribute('aria-label', 'Abrir calendário');
    button.textContent = '📅';
    wrap.appendChild(button);

    // Seletor nativo escondido: só serve para escolher a data no calendário; o texto continua sendo a fonte.
    const picker = document.createElement('input');
    picker.type = 'date';
    picker.min = MIN_ISO;
    picker.tabIndex = -1;
    picker.className = 'date-picker-native';
    picker.setAttribute('aria-hidden', 'true');
    wrap.appendChild(picker);

    button.addEventListener('click', () => {
      if (input.readOnly || input.disabled) return;
      picker.value = brToIso(rawGet()) || '';
      if (typeof picker.showPicker === 'function') picker.showPicker(); else picker.click();
    });
    picker.addEventListener('change', () => {
      if (!picker.value) return;
      rawSet(isoToBr(picker.value));
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });

    refreshValidity(input);
    return input;
  }

  /** Mensagem do navegador (balão no envio do formulário) e borda vermelha para data incompleta ou fora da faixa. */
  function refreshValidity(input, mostrarBorda = false) {
    const raw = rawOf(input) ? rawOf(input).get().trim() : '';
    let msg = '';
    if (raw !== '') {
      const iso = brToIso(raw);
      if (iso === '') msg = MSG_INVALIDA;
      else if (iso < MIN_ISO) msg = MSG_MINIMA;
    }
    if (typeof input.setCustomValidity === 'function') input.setCustomValidity(msg);
    if (input.classList) {
      if (msg === '') input.classList.remove('campo-invalido');
      else if (mostrarBorda) input.classList.add('campo-invalido');
    }
    return msg;
  }

  const api = {
    MIN_ISO,
    maskBr,
    brToIso,
    isoToBr,
    attach,
    /** Valor ISO do campo ('' se vazio, incompleto ou inexistente). */
    iso: (input) => (rawOf(input) ? brToIso(rawOf(input).get()) : String(input.value || '')),
    /** true se há texto no campo, mas ele não é uma data completa e real. */
    invalid: (input) => (rawOf(input) ? rawOf(input).get().trim() !== '' && brToIso(rawOf(input).get()) === '' : false),
    setIso: (input, iso) => { input.value = iso; },
    /** Texto bruto (dd/mm/aaaa) que está no campo. */
    text: (input) => (rawOf(input) ? rawOf(input).get() : String(input.value || '')),
  };

  if (typeof module !== 'undefined' && module.exports) module.exports = api; // testes em Node
  if (typeof window !== 'undefined') {
    window.DateField = api;
    // Aplica sozinho em todo campo de data editável da página (os somente-leitura ficam como estão).
    if (typeof document !== 'undefined' && document.querySelectorAll) {
      document.querySelectorAll('input[type="date"]').forEach((el) => { if (!el.readOnly && !el.disabled) attach(el); });
    }
  }
})();
