/*
 * Pareamento da TV por código (página /tv).
 *
 * ES5 de propósito (var, function, XMLHttpRequest; sem setas/let/const/fetch): roda em navegadores antigos
 * de Fire Stick e smart TVs. Texto do servidor entra só com textContent.
 *
 * Fluxo:
 *  1. Se já há um token guardado (localStorage), confere em /tv/{token}/state: se existe (ou se estamos sem
 *     internet), abre /tv/{token}; se foi revogado (404), esquece o token e pede um código novo.
 *  2. Sem token: POST /tv/parear devolve um código curto (mostrado na tela) e um segredo (nunca mostrado).
 *  3. A cada poucos segundos consulta /tv/parear/consulta com o segredo. Quando o administrador digita o código
 *     no painel, a resposta traz o token (uma única vez): guarda e abre /tv/{token}.
 *  4. Código vencido (ou desconhecido): pede outro automaticamente.
 */
(function () {
    'use strict';

    var CHAVE = 'avisos_tv_token';
    var corpo = document.body;
    var intervaloMs = Math.max(2, parseInt(corpo.getAttribute('data-intervalo'), 10) || 4) * 1000;
    var elCodigo = document.getElementById('parear-codigo');
    var elStatus = document.getElementById('parear-status');
    var segredo = '';
    var falhas = 0;

    function ler() {
        try { return window.localStorage.getItem(CHAVE); } catch (e) { return null; }
    }
    function guardar(valor) {
        try { window.localStorage.setItem(CHAVE, valor); } catch (e) { /* sem armazenamento: o pareamento vale só até fechar a página */ }
    }
    function apagar() {
        try { window.localStorage.removeItem(CHAVE); } catch (e) { /* nada a fazer */ }
    }

    function status(texto) { elStatus.textContent = texto; }

    function abrir(token) {
        status('Pronto! Abrindo os avisos...');
        window.location.replace('/tv/' + token);
    }

    function enviar(url, dados, aoTerminar) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', url, true);
        xhr.timeout = 15000;
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function () { aoTerminar(xhr.status, xhr.responseText, xhr); };
        xhr.onerror = xhr.ontimeout = function () { aoTerminar(0, '', xhr); };
        xhr.send(dados);
    }

    function lerJson(texto) {
        try { return JSON.parse(texto); } catch (e) { return null; }
    }

    /** Espera maior a cada falha seguida (até 1 min), para não martelar o servidor sem internet. */
    function espera() {
        return Math.min(intervaloMs * Math.pow(2, Math.min(falhas, 4)), 60000);
    }

    function mostrarCodigo(codigo) {
        // "ABC234" -> "ABC 234": mais fácil de ler de longe e de digitar.
        elCodigo.textContent = codigo.substring(0, 3) + ' ' + codigo.substring(3);
    }

    function pedirCodigo() {
        segredo = '';
        enviar('/tv/parear', '', function (codigoHttp, texto, xhr) {
            var dados = codigoHttp === 200 ? lerJson(texto) : null;
            if (dados && dados.codigo && dados.segredo) {
                falhas = 0;
                segredo = dados.segredo;
                mostrarCodigo(dados.codigo);
                status('Aguardando o pareamento. O código vale por alguns minutos e muda sozinho.');
                setTimeout(consultar, intervaloMs);
                return;
            }
            falhas++;
            var retry = codigoHttp === 429 ? parseInt(xhr.getResponseHeader('Retry-After'), 10) : 0;
            status(codigoHttp === 0 ? 'Sem conexão. Tentando de novo...' : 'Aguarde um instante...');
            setTimeout(pedirCodigo, Math.max(espera(), (retry || 0) * 1000));
        });
    }

    function consultar() {
        enviar('/tv/parear/consulta', 'segredo=' + encodeURIComponent(segredo), function (codigoHttp, texto) {
            var dados = codigoHttp === 200 ? lerJson(texto) : null;
            if (dados && dados.estado === 'pareado' && /^[A-Za-z0-9]{12}$/.test(dados.token || '')) {
                guardar(dados.token);
                abrir(dados.token);
                return;
            }
            if (codigoHttp === 404 || (dados && dados.estado === 'expirado')) {
                falhas = 0;
                pedirCodigo();
                return;
            }
            if (dados && dados.estado === 'aguardando') {
                falhas = 0;
                setTimeout(consultar, intervaloMs);
                return;
            }
            falhas++;
            status(codigoHttp === 0 ? 'Sem conexão. Tentando de novo...' : 'Aguarde um instante...');
            setTimeout(consultar, espera());
        });
    }

    /** Há token guardado: confere se ainda vale antes de abrir. Sem internet, abre mesmo assim (a tela tem cópia offline). */
    function conferirToken(token) {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '/tv/' + token + '/state', true);
        xhr.timeout = 10000;
        xhr.onload = function () {
            if (xhr.status === 404) {
                apagar();
                pedirCodigo();
            } else {
                abrir(token);
            }
        };
        xhr.onerror = xhr.ontimeout = function () { abrir(token); };
        xhr.send();
    }

    var guardado = ler();
    if (guardado && /^[A-Za-z0-9]{12}$/.test(guardado)) {
        status('Abrindo os avisos...');
        conferirToken(guardado);
    } else {
        if (guardado) { apagar(); }
        pedirCodigo();
    }
}());
