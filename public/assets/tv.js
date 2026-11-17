/*
 * Tela da TV: rotação dos avisos, relógio e sincronização com o servidor.
 *
 * Escrito em ES5 de propósito (var, function, XMLHttpRequest, sem setas/let/const/fetch): precisa rodar
 * em navegadores antigos de Fire Stick e smart TVs. Todo texto vindo do servidor entra na página com
 * textContent (nunca innerHTML), então não há como injetar HTML.
 *
 * Como funciona:
 *  - A cada "intervalo" segundos consulta /tv/{token}/state (isso também é o heartbeat do dispositivo).
 *  - O estado traz a hora do servidor; deslocamento = server_epoch - Date.now(). Todo horário na tela e
 *    todo teste de "este aviso está no ar?" usa Date.now() + deslocamento, então um relógio errado no
 *    aparelho não faz avisos entrarem/saírem no horário errado.
 *  - Sem internet, continua com o último estado guardado (localStorage e Service Worker) e decide pelo
 *    relógio corrigido: o que venceu sai da tela e o agendado entra na hora certa.
 *  - Só redesenha quando "version" muda. Avisos de tipo FIXO (ex.: Urgente e Fixo, e os que o gerente marcar) ficam numa faixa no topo (40% da altura),
 *    todos ao mesmo tempo, dividindo a faixa; os demais passam em carrossel abaixo dela.
 */
(function () {
    'use strict';

    // Ícones das etiquetas da faixa (mesmos desenhos dos painéis). Caminhos fixos no código, nunca vindos do servidor.
    var ICONES = {
        urgente: ['M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z', 'M12 9v4', 'M12 17h.01'],
        fixo: ['M12 17v5', 'M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z']
    };
    var BASE_MS = 12000;           // tempo mínimo de cada aviso
    var POR_CARACTERE_MS = 40;     // mais texto, mais tempo (até EXTRA_MAX_MS)
    var EXTRA_MAX_MS = 13000;
    var VIDEO_MAX_MS = 60000;      // se um vídeo não terminar (erro/travou), segue depois disso
    var TROCA_MS = 350;            // duração do fade entre avisos
    var RECARGA_MS = 6 * 3600000;  // recarrega a página a cada 6 h (libera memória; pega novas versões do código)
    var BRASILIA_MS = -3 * 3600000; // Brasília = UTC-3 (sem horário de verão desde 2019)

    /* ---------- Lógica pura (testada em tests/js/logica.html) ---------- */

    function interpretar(avisos) {
        var lista = [];
        for (var i = 0; i < avisos.length; i++) {
            var a = avisos[i];
            lista.push({
                dados: a,
                id: a.id,
                inicio: Date.parse(a.inicio_em),
                fim: Date.parse(a.fim_em)
            });
        }
        return lista;
    }

    /** Fixo = o tipo do aviso foi marcado como fixo (faixa do topo, só texto). Vem do servidor em "fixo". */
    function ehFixo(aviso) {
        return aviso.fixo === true;
    }

    /** "08:30" → minutos desde a meia-noite. */
    function minutosDoDia(hhmm) {
        var partes = String(hhmm).split(':');
        return parseInt(partes[0], 10) * 60 + parseInt(partes[1], 10);
    }

    /**
     * Repetição do aviso dentro do período: dias da semana (0 = domingo) e janela de horário, em horário de Brasília,
     * pelo relógio corrigido. Sem "repete", vale o período inteiro. Mesma regra do PHP (App\Support\Recorrencia).
     */
    function recorrenciaAtiva(repete, agoraMs) {
        if (!repete) { return true; }
        var d = new Date(agoraMs + BRASILIA_MS);
        if (repete.dias && repete.dias.length) {
            var achou = false;
            for (var i = 0; i < repete.dias.length; i++) {
                if (repete.dias[i] === d.getUTCDay()) { achou = true; }
            }
            if (!achou) { return false; }
        }
        if (repete.inicio && repete.fim) {
            var minutos = d.getUTCHours() * 60 + d.getUTCMinutes();
            if (minutos < minutosDoDia(repete.inicio) || minutos >= minutosDoDia(repete.fim)) { return false; }
        }
        return true;
    }

    function noAr(a, agoraMs) {
        return a.inicio <= agoraMs && agoraMs < a.fim && recorrenciaAtiva(a.dados.repete, agoraMs);
    }

    /** Avisos fixos/urgentes no ar agora (todos aparecem ao mesmo tempo): urgentes primeiro, depois pelo início. */
    function selecionarFixos(interpretados, agoraMs) {
        var lista = [];
        for (var i = 0; i < interpretados.length; i++) {
            var a = interpretados[i];
            if (ehFixo(a.dados) && noAr(a, agoraMs)) { lista.push(a); }
        }
        lista.sort(function (x, y) {
            var ux = x.dados.tipo === 'urgente' ? 0 : 1;
            var uy = y.dados.tipo === 'urgente' ? 0 : 1;
            return (ux - uy) || (x.inicio - y.inicio) || (x.id - y.id);
        });
        return lista;
    }

    /** Avisos do carrossel no ar agora (todos os que não são fixos/urgentes), pela ordem de início. */
    function selecionarCarrossel(interpretados, agoraMs) {
        var lista = [];
        for (var i = 0; i < interpretados.length; i++) {
            var a = interpretados[i];
            if (!ehFixo(a.dados) && noAr(a, agoraMs)) { lista.push(a); }
        }
        lista.sort(function (x, y) { return (x.inicio - y.inicio) || (x.id - y.id); });
        return lista;
    }

    /**
     * Como dividir a faixa entre n avisos: até 3 numa linha; de 4 a 6 em duas linhas (2 ou 3 colunas);
     * acima disso, duas linhas com mais colunas (a letra encolhe, a faixa continua do mesmo tamanho).
     */
    function disposicao(n) {
        if (n <= 0) { return { colunas: 0, linhas: 0 }; }
        if (n <= 3) { return { colunas: n, linhas: 1 }; }
        if (n === 4) { return { colunas: 2, linhas: 2 }; }
        return { colunas: Math.min(Math.ceil(n / 2), 5), linhas: 2 };
    }

    /** Quanto tempo o aviso fica na tela (null = vídeo: até terminar). */
    function duracaoMs(aviso) {
        if (aviso.midia && aviso.midia.tipo === 'video') {
            return null;
        }
        var extra = Math.min((aviso.corpo || '').length * POR_CARACTERE_MS, EXTRA_MAX_MS);
        return BASE_MS + extra;
    }

    function doisDigitos(n) { return (n < 10 ? '0' : '') + n; }

    /** "14:05" em horário de Brasília a partir de um instante em milissegundos. */
    function horaBrasilia(agoraMs) {
        var d = new Date(agoraMs + BRASILIA_MS);
        return doisDigitos(d.getUTCHours()) + ':' + doisDigitos(d.getUTCMinutes());
    }

    window.TvLogica = {
        interpretar: interpretar,
        ehFixo: ehFixo,
        recorrenciaAtiva: recorrenciaAtiva,
        selecionarFixos: selecionarFixos,
        selecionarCarrossel: selecionarCarrossel,
        disposicao: disposicao,
        duracaoMs: duracaoMs,
        horaBrasilia: horaBrasilia
    };

    var corpoPagina = document.body;
    if (!corpoPagina || !corpoPagina.getAttribute('data-estado-url')) {
        return; // página de testes: só expõe a lógica
    }

    /* ---------- Estado e persistência ---------- */

    var estadoUrl = corpoPagina.getAttribute('data-estado-url');
    var chaveEstado = 'tv:estado:' + estadoUrl;
    var chaveOffset = 'tv:offset:' + estadoUrl;
    var intervaloMs = (parseInt(corpoPagina.getAttribute('data-intervalo'), 10) || 45) * 1000;

    var el = {
        tv: document.getElementById('tv'),
        fixos: document.getElementById('tv-fixos'),
        corpo: document.getElementById('tv-corpo'),
        ociosoRelogio: document.getElementById('tv-ocioso-relogio'),
        ociosoTexto: document.getElementById('tv-ocioso-texto'),
        offline: document.getElementById('tv-offline')
    };

    var estado = null;          // último estado recebido
    var interpretados = [];     // avisos do estado com datas já convertidas
    var deslocamentoMs = 0;     // hora do servidor - hora do aparelho
    var atual = null;           // aviso do carrossel em tela
    var assinaturaFixos = '';   // identifica o que a faixa do topo mostra (para só redesenhar quando muda)
    var temFixos = false;
    var inicioDoAvisoMs = 0;
    var videoTerminou = false;
    var trocando = false;
    var falhas = 0;
    var versaoDoCodigo = null;
    var iniciouEm = Date.now();

    function guardar(chave, valor) {
        try { window.localStorage.setItem(chave, valor); } catch (e) { /* sem armazenamento: segue sem cache local */ }
    }

    function ler(chave) {
        try { return window.localStorage.getItem(chave); } catch (e) { return null; }
    }

    function agora() { return Date.now() + deslocamentoMs; }

    function lerJson(texto) {
        try { return JSON.parse(texto); } catch (e) { return null; }
    }

    /* ---------- Desenho ---------- */

    /** modo: 'aviso' (carrossel), 'ocioso' (nada no ar), 'semCarrossel' (só avisos fixos/urgentes, em tela cheia) ou 'relogio' (só o relógio). */
    function definirModo(modo) {
        var classe = 'tv';
        if (modo === 'ocioso') { classe += ' tv-modo-ocioso'; }
        if (modo === 'relogio') { classe += ' tv-modo-relogio'; }
        if (temFixos && modo !== 'relogio') { classe += ' tv-com-fixos'; }
        if (modo === 'semCarrossel') { classe += ' tv-sem-carrossel'; }
        if (el.tv.className !== classe) { el.tv.className = classe; }
    }

    function criar(tag, classe, texto) {
        var n = document.createElement(tag);
        if (classe) { n.className = classe; }
        if (texto !== undefined && texto !== null) { n.textContent = texto; }
        return n;
    }

    function criarIcone(tipo) {
        var caminhos = ICONES[tipo];
        if (!caminhos || !document.createElementNS) { return null; }
        var NS = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(NS, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2.4');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');
        for (var i = 0; i < caminhos.length; i++) {
            var caminho = document.createElementNS(NS, 'path');
            caminho.setAttribute('d', caminhos[i]);
            svg.appendChild(caminho);
        }
        return svg;
    }

    /**
     * Cores do tipo do aviso: etiqueta na cor principal, cartão no tom claro e título no tom escuro. Sem "visual"
     * (tipo sem cor) limpa tudo e vale o visual neutro do CSS. CSSOM (style.x = ...) não é bloqueado pelo CSP.
     */
    function aplicarVisual(cartao, etiqueta, titulo, visual, comBorda) {
        cartao.style.backgroundColor = visual ? visual.fundo : '';
        cartao.style.border = visual && comBorda ? '0.1em solid ' + visual.borda : '';
        etiqueta.style.backgroundColor = visual ? visual.principal : '';
        etiqueta.style.color = visual ? visual.texto_tag : '';
        titulo.style.color = visual ? visual.titulo : '';
    }

    /** Desenha a faixa do topo com todos os avisos fixos/urgentes; só mexe no DOM quando algo mudou. */
    function atualizarFixos(lista) {
        var ass = '';
        for (var i = 0; i < lista.length; i++) { ass += lista[i].id + ','; }
        temFixos = lista.length > 0;
        if (ass === assinaturaFixos) { return; }
        assinaturaFixos = ass;

        while (el.fixos.firstChild) { el.fixos.removeChild(el.fixos.firstChild); }
        if (!temFixos) { el.fixos.className = 'tv-fixos'; return; }

        var d = disposicao(lista.length);
        el.fixos.className = 'tv-fixos tv-fixos-cols-' + d.colunas + ' tv-fixos-rows-' + d.linhas;
        for (var j = 0; j < lista.length; j++) {
            var dados = lista[j].dados;
            var cartao = criar('article', 'tv-fixo tv-fixo-' + dados.tipo);
            var conteudo = criar('div', 'tv-fixo-conteudo');
            var etiqueta = criar('span', 'tv-fixo-tipo');
            var icone = criarIcone(dados.tipo === 'urgente' ? 'urgente' : 'fixo');
            if (icone) { etiqueta.appendChild(icone); }
            etiqueta.appendChild(criar('span', '', dados.tipo_nome || dados.tipo));
            // Título à esquerda e etiqueta à direita, na mesma linha.
            var cabeca = criar('div', 'tv-cabeca');
            var titulo = criar('h2', 'tv-fixo-titulo', dados.titulo);
            cabeca.appendChild(titulo);
            cabeca.appendChild(etiqueta);
            aplicarVisual(cartao, etiqueta, titulo, dados.visual, true);
            conteudo.appendChild(cabeca);
            if (dados.corpo) { conteudo.appendChild(criar('p', 'tv-fixo-texto', dados.corpo)); }
            cartao.appendChild(conteudo);
            el.fixos.appendChild(cartao);
        }
    }

    function montarAviso(a) {
        var d = a.dados;
        while (el.corpo.firstChild) { el.corpo.removeChild(el.corpo.firstChild); } // o conteúdo novo entra só por textContent
        var tam = (d.corpo || '').length;
        // Texto curto ganha letra maior, para o cartão não ficar vazio; com foto/vídeo o espaço é dividido.
        var escala = d.midia ? '' : (tam <= 110 ? ' tv-corpo-curto' : (tam <= 220 ? ' tv-corpo-medio' : ''));
        el.corpo.className = 'tv-corpo' + (d.midia ? ' tv-com-midia' : '') + escala;

        var bloco = criar('div', 'tv-texto-bloco');
        var cabeca = criar('div', 'tv-cabeca');
        var tituloCarrossel = criar('h2', 'tv-titulo', d.titulo);
        var etiquetaCarrossel = criar('span', 'tv-tipo', d.tipo_nome || d.tipo);
        cabeca.appendChild(tituloCarrossel);
        cabeca.appendChild(etiquetaCarrossel);
        aplicarVisual(el.corpo, etiquetaCarrossel, tituloCarrossel, d.visual, false);
        bloco.appendChild(cabeca);
        if (d.corpo) { bloco.appendChild(criar('p', 'tv-texto', d.corpo)); }
        el.corpo.appendChild(bloco);

        videoTerminou = false;
        if (d.midia) {
            var caixa = criar('div', 'tv-midia');
            var midia;
            if (d.midia.tipo === 'video') {
                midia = document.createElement('video');
                midia.muted = true;
                midia.setAttribute('muted', '');
                midia.setAttribute('playsinline', '');
                midia.autoplay = true;
                midia.onended = function () { videoTerminou = true; };
                midia.onerror = function () { videoTerminou = true; };
                midia.src = d.midia.url;
                if (midia.play) {
                    var promessa = midia.play();
                    if (promessa && promessa.catch) { promessa.catch(function () { /* o limite de tempo cuida */ }); }
                }
            } else {
                midia = document.createElement('img');
                midia.alt = '';
                midia.onerror = function () { if (caixa.parentNode) { caixa.parentNode.removeChild(caixa); } };
                midia.src = d.midia.url;
            }
            caixa.appendChild(midia);
            el.corpo.appendChild(caixa);
        }
    }

    /** Troca o que está na tela, com um fade curto. */
    function mostrar(a) {
        if (trocando) { return; }
        trocando = true;
        el.corpo.className += ' tv-saindo';
        setTimeout(function () {
            atual = a;
            inicioDoAvisoMs = Date.now();
            montarAviso(a);
            definirModo('aviso');
            // força o navegador a aplicar o estado "saindo" antes de remover a classe (fade-in)
            void el.corpo.offsetWidth;
            el.corpo.className = el.corpo.className.replace(' tv-saindo', '');
            trocando = false;
        }, atual ? TROCA_MS : 0);
    }

    function atualizarRelogio() {
        var hora = horaBrasilia(agora());
        if (el.ociosoRelogio.textContent !== hora) { el.ociosoRelogio.textContent = hora; }
    }

    /** Executado a cada segundo: relógio, faixa do topo, o que está no carrossel e hora de trocar de aviso. */
    function ciclo() {
        atualizarRelogio();

        if (!estado || estado.bloqueado || estado.revogado) {
            atual = null;
            atualizarFixos([]);
            definirModo('relogio');
            return;
        }

        var t = agora();
        atualizarFixos(selecionarFixos(interpretados, t));
        var lista = selecionarCarrossel(interpretados, t);

        if (lista.length === 0) {
            atual = null;
            definirModo(temFixos ? 'semCarrossel' : 'ocioso');
            return;
        }
        if (trocando) { return; }

        var posicao = -1;
        for (var i = 0; i < lista.length; i++) {
            if (atual && lista[i].id === atual.id) { posicao = i; break; }
        }

        if (posicao === -1) { // nada em tela, ou o aviso saiu do ar (venceu, foi desativado)
            mostrar(lista[0]);
            return;
        }

        definirModo('aviso');
        var duracao = duracaoMs(atual.dados);
        var passou = Date.now() - inicioDoAvisoMs;
        var acabou = duracao === null ? (videoTerminou || passou >= VIDEO_MAX_MS) : passou >= duracao;
        if (acabou && lista.length > 1) {
            mostrar(lista[(posicao + 1) % lista.length]);
        } else if (acabou) {
            inicioDoAvisoMs = Date.now(); // só um aviso: segue nele (e reinicia o vídeo)
            if (duracao === null) { montarAviso(atual); }
        }
    }

    /* ---------- Sincronização ---------- */

    function aplicarEstado(novo) {
        var mudou = !estado || estado.version !== novo.version;
        estado = novo;
        if (!mudou) { return; }

        interpretados = interpretar(novo.avisos || []);
        preCarregarEMLimpar(novo);
        atual = null;          // força reavaliar o que mostrar
        assinaturaFixos = '#';  // e redesenhar a faixa do topo
        ciclo();
    }

    function sucesso(novo) {
        falhas = 0;
        el.offline.hidden = true;
        if (typeof novo.server_epoch === 'number') {
            deslocamentoMs = novo.server_epoch * 1000 - Date.now();
            guardar(chaveOffset, String(deslocamentoMs));
        }
        guardar(chaveEstado, JSON.stringify(novo));
        if (novo.app) {
            if (versaoDoCodigo === null) { versaoDoCodigo = novo.app; }
            else if (versaoDoCodigo !== novo.app) { recarregar(); return; }
        }
        aplicarEstado(novo);
    }

    function falhou() {
        falhas++;
        if (falhas >= 2) { el.offline.hidden = false; }
    }

    function consultar() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', estadoUrl, true);
        xhr.timeout = 15000;
        xhr.onload = function () {
            if (xhr.status === 200) {
                var dados = lerJson(xhr.responseText);
                if (dados && dados.avisos) { sucesso(dados); } else { falhou(); }
            } else if (xhr.status === 404) {
                // Dispositivo revogado/token trocado: some o conteúdo e fica só o relógio.
                estado = { version: 'revogado', revogado: true, avisos: [] };
                interpretados = [];
                guardar(chaveEstado, '');
                falhas = 0;
                el.offline.hidden = true;
                ciclo();
            } else {
                falhou();
            }
            agendar();
        };
        xhr.onerror = xhr.ontimeout = function () { falhou(); agendar(); };
        xhr.send();
    }

    function agendar() {
        // Em falha, espera mais (até 5 min) para não martelar o servidor; com sucesso volta ao normal. Variação de ±10%.
        var base = Math.min(intervaloMs * Math.pow(2, Math.min(falhas, 4)), 300000);
        setTimeout(consultar, base * (0.9 + Math.random() * 0.2));
    }

    function recarregar() {
        try { window.location.reload(); } catch (e) { /* sem ação */ }
    }

    /* ---------- Cache offline das mídias (Service Worker) ---------- */

    function preCarregarEMLimpar(novo) {
        var urls = [];
        for (var i = 0; i < (novo.avisos || []).length; i++) {
            var m = novo.avisos[i].midia;
            // Avisos fixos/urgentes mostram só texto: a mídia deles não é usada, então não é guardada.
            if (m && !ehFixo(novo.avisos[i])) { urls.push(m.url); }
        }

        // Guarda no cache os arquivos dos avisos atuais e futuros (o Service Worker intercepta e salva).
        if (navigator.serviceWorker && navigator.serviceWorker.controller) {
            var pos = 0;
            var proximo = function () {
                if (pos >= urls.length) { return; }
                var xhr = new XMLHttpRequest();
                xhr.open('GET', urls[pos++], true);
                xhr.onloadend = proximo;
                xhr.send();
            };
            proximo();
        }

        // Apaga do cache as mídias de avisos que não existem mais (evita encher o aparelho).
        if (window.caches && window.caches.keys) {
            window.caches.keys().then(function (nomes) {
                for (var n = 0; n < nomes.length; n++) {
                    if (nomes[n].indexOf('tv-midia-') !== 0) { continue; }
                    (function (nomeDoCache) {
                        window.caches.open(nomeDoCache).then(function (cache) {
                            cache.keys().then(function (requisicoes) {
                                for (var r = 0; r < requisicoes.length; r++) {
                                    var caminho = requisicoes[r].url.replace(/^https?:\/\/[^\/]+/, '');
                                    if (urls.indexOf(caminho) === -1) { cache.delete(requisicoes[r]); }
                                }
                            });
                        });
                    })(nomes[n]);
                }
            });
        }
    }

    function registrarServiceWorker() {
        if (!navigator.serviceWorker || !navigator.serviceWorker.register) { return; }
        try {
            navigator.serviceWorker.register('/tv/sw.js', { scope: '/tv/' })['catch'](function () { /* sem SW: funciona só online */ });
        } catch (e) { /* ignora */ }
    }

    /* ---------- Início ---------- */

    function iniciar() {
        var valorOffset = parseFloat(ler(chaveOffset));
        if (!isNaN(valorOffset)) { deslocamentoMs = valorOffset; }

        // Estado inicial: o mais recente entre o que veio na página e o guardado localmente.
        var daPagina = lerJson(corpoPagina.getAttribute('data-estado') || '');
        var guardado = lerJson(ler(chaveEstado) || '');
        var inicial = daPagina;
        if (guardado && guardado.avisos && (!daPagina || guardado.server_epoch > daPagina.server_epoch)) {
            inicial = guardado;
        }
        if (inicial && inicial.avisos) {
            if (daPagina && daPagina.app) { versaoDoCodigo = daPagina.app; }
            aplicarEstado(inicial);
        } else {
            definirModo('ocioso');
        }

        atualizarRelogio();
        setInterval(ciclo, 1000);
        setTimeout(function () { consultar(); }, 1500);
        setTimeout(function () { if (Date.now() - iniciouEm >= RECARGA_MS) { recarregar(); } }, RECARGA_MS);
        registrarServiceWorker();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
