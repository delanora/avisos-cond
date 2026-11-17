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
 *  - Só redesenha quando "version" muda. Aviso urgente tem prioridade: enquanto existir algum no ar,
 *    só os urgentes são exibidos.
 */
(function () {
    'use strict';

    var TIPOS = { comum: 'Comum', urgente: 'Urgente', manutencao: 'Manutenção', assembleia: 'Assembleia' };
    var BASE_MS = 12000;           // tempo mínimo de cada aviso
    var POR_CARACTERE_MS = 40;     // mais texto, mais tempo (até EXTRA_MAX_MS)
    var EXTRA_MAX_MS = 13000;
    var URGENTE_EXTRA_MS = 3000;
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

    /** Avisos que devem aparecer agora. Com algum urgente no ar, só os urgentes. */
    function selecionar(interpretados, agoraMs) {
        var ativos = [];
        var urgentes = [];
        for (var i = 0; i < interpretados.length; i++) {
            var a = interpretados[i];
            if (a.inicio <= agoraMs && agoraMs < a.fim) {
                ativos.push(a);
                if (a.dados.tipo === 'urgente') {
                    urgentes.push(a);
                }
            }
        }
        var lista = urgentes.length > 0 ? urgentes : ativos;
        lista.sort(function (x, y) { return (x.inicio - y.inicio) || (x.id - y.id); });
        return lista;
    }

    /** Quanto tempo o aviso fica na tela (null = vídeo: até terminar). */
    function duracaoMs(aviso) {
        if (aviso.midia && aviso.midia.tipo === 'video') {
            return null;
        }
        var extra = Math.min((aviso.corpo || '').length * POR_CARACTERE_MS, EXTRA_MAX_MS);
        return BASE_MS + extra + (aviso.tipo === 'urgente' ? URGENTE_EXTRA_MS : 0);
    }

    function doisDigitos(n) { return (n < 10 ? '0' : '') + n; }

    /** "14:05" em horário de Brasília a partir de um instante em milissegundos. */
    function horaBrasilia(agoraMs) {
        var d = new Date(agoraMs + BRASILIA_MS);
        return doisDigitos(d.getUTCHours()) + ':' + doisDigitos(d.getUTCMinutes());
    }

    window.TvLogica = {
        interpretar: interpretar,
        selecionar: selecionar,
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
        corpo: document.getElementById('tv-corpo'),
        logo: document.getElementById('tv-logo'),
        relogio: document.getElementById('tv-relogio'),
        ociosoRelogio: document.getElementById('tv-ocioso-relogio'),
        offline: document.getElementById('tv-offline')
    };

    var estado = null;          // último estado recebido
    var interpretados = [];     // avisos do estado com datas já convertidas
    var deslocamentoMs = 0;     // hora do servidor - hora do aparelho
    var atual = null;           // aviso em tela
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

    function aplicarTema(tema) {
        if (!tema || !document.documentElement.style || !document.documentElement.style.setProperty) { return; }
        var s = document.documentElement.style;
        // CSSOM não é bloqueado pelo CSP (só estilo inline escrito no HTML).
        s.setProperty('--tv-primaria', tema.primaria);
        s.setProperty('--tv-primaria-texto', tema.primaria_texto);
        s.setProperty('--tv-secundaria', tema.secundaria);
        s.setProperty('--tv-secundaria-texto', tema.secundaria_texto);
    }

    function aplicarLogo(url) {
        if (url && el.logo.getAttribute('src') !== url) { el.logo.setAttribute('src', url); }
    }

    function definirModo(modo) {
        el.tv.className = 'tv' + (modo === 'ocioso' ? ' tv-modo-ocioso' : '') + (modo === 'relogio' ? ' tv-modo-relogio' : '') +
            (modo === 'aviso' && atual && atual.dados.tipo === 'urgente' ? ' tv-urgente' : '');
    }

    function criar(tag, classe, texto) {
        var n = document.createElement(tag);
        if (classe) { n.className = classe; }
        if (texto !== undefined && texto !== null) { n.textContent = texto; }
        return n;
    }

    function montarAviso(a) {
        var d = a.dados;
        while (el.corpo.firstChild) { el.corpo.removeChild(el.corpo.firstChild); } // o conteúdo novo entra só por textContent
        el.corpo.className = 'tv-corpo' + (d.midia ? ' tv-com-midia' : '');

        var bloco = criar('div', 'tv-texto-bloco');
        bloco.appendChild(criar('span', 'tv-tipo', TIPOS[d.tipo] || d.tipo));
        bloco.appendChild(criar('h2', 'tv-titulo', d.titulo));
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
        if (el.relogio.textContent !== hora) { el.relogio.textContent = hora; }
        if (el.ociosoRelogio.textContent !== hora) { el.ociosoRelogio.textContent = hora; }
    }

    /** Executado a cada segundo: relógio, o que está no ar e hora de trocar de aviso. */
    function ciclo() {
        atualizarRelogio();

        if (!estado || estado.bloqueado || estado.revogado) {
            atual = null;
            definirModo('relogio');
            return;
        }

        var lista = selecionar(interpretados, agora());
        if (lista.length === 0) {
            atual = null;
            definirModo('ocioso');
            return;
        }
        if (trocando) { return; }

        var posicao = -1;
        for (var i = 0; i < lista.length; i++) {
            if (atual && lista[i].id === atual.id) { posicao = i; break; }
        }

        if (posicao === -1) { // nada em tela, ou o aviso saiu do ar (venceu, foi desativado, apareceu urgente)
            mostrar(lista[0]);
            return;
        }

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
        aplicarTema(novo.tema);
        aplicarLogo(novo.logo);
        preCarregarEMLimpar(novo);
        atual = null; // força reavaliar o que mostrar
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
            if (m) { urls.push(m.url); }
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
