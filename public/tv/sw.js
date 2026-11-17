/*
 * Service Worker da TV: faz a tela continuar funcionando sem internet.
 * Escopo: /tv/ (por isso o arquivo fica em /tv/sw.js e não controla o painel nem o login).
 *
 *  - Página /tv/{token} e /state: rede primeiro; se a rede falhar, usa a última cópia.
 *  - Mídias (/tv/{token}/media/...): cache primeiro (o nome do arquivo é aleatório e nunca muda de conteúdo).
 *    Atende pedidos "Range" a partir da cópia guardada (o <video> precisa disso).
 *  - /assets/ (tv.js, tv.css, fonte, logo padrão): serve a cópia e atualiza em segundo plano.
 *  - Respostas de erro (ex.: 404 de token revogado) NUNCA entram no cache nem substituem uma cópia boa;
 *    e um 404 da rede é repassado (não se cai na cópia antiga), para um dispositivo revogado parar de exibir.
 * ES5 de propósito (navegadores antigos).
 */
var VERSAO = 'v1';
var CACHE_PAGINAS = 'tv-paginas-' + VERSAO;
var CACHE_ESTADO = 'tv-estado-' + VERSAO;
var CACHE_MIDIA = 'tv-midia-' + VERSAO;
var CACHE_ASSETS = 'tv-assets-' + VERSAO;
var ATUAIS = [CACHE_PAGINAS, CACHE_ESTADO, CACHE_MIDIA, CACHE_ASSETS];
var LIMITE_REDE_MS = 6000;

self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (evento) {
    evento.waitUntil(
        caches.keys().then(function (nomes) {
            return Promise.all(nomes.map(function (nome) {
                return ATUAIS.indexOf(nome) === -1 ? caches.delete(nome) : null;
            }));
        }).then(function () { return self.clients.claim(); })
    );
});

function comLimite(promessa, ms) {
    return new Promise(function (resolve, reject) {
        var temporizador = setTimeout(function () { reject(new Error('tempo esgotado')); }, ms);
        promessa.then(function (r) { clearTimeout(temporizador); resolve(r); }, function (e) { clearTimeout(temporizador); reject(e); });
    });
}

/** Rede primeiro; se falhar (sem internet, demora demais), a última cópia boa. */
function redePrimeiro(requisicao, nomeDoCache) {
    return comLimite(fetch(requisicao), LIMITE_REDE_MS).then(function (resposta) {
        if (resposta && resposta.ok) {
            var copia = resposta.clone();
            caches.open(nomeDoCache).then(function (cache) { cache.put(requisicao, copia); });
        }
        return resposta; // inclusive 404/429: são respostas do servidor e valem
    }, function () {
        return caches.open(nomeDoCache).then(function (cache) {
            return cache.match(requisicao).then(function (guardada) {
                return guardada || new Response('', { status: 504, statusText: 'Sem conexão' });
            });
        });
    });
}

/** Copia guardada primeiro; atualiza em segundo plano. */
function copiaPrimeiro(requisicao, nomeDoCache) {
    return caches.open(nomeDoCache).then(function (cache) {
        return cache.match(requisicao).then(function (guardada) {
            var buscando = fetch(requisicao).then(function (resposta) {
                if (resposta && resposta.ok) { cache.put(requisicao, resposta.clone()); }
                return resposta;
            }, function () { return null; });
            return guardada || buscando.then(function (r) { return r || new Response('', { status: 504 }); });
        });
    });
}

/** Responde a um pedido "Range: bytes=a-b" a partir de uma resposta completa guardada. */
function fatiar(requisicao, resposta) {
    var cabecalho = requisicao.headers.get('range');
    var partes = /^bytes=(\d*)-(\d*)$/.exec(cabecalho || '');
    if (!partes) { return Promise.resolve(resposta); }
    return resposta.arrayBuffer().then(function (buffer) {
        var total = buffer.byteLength;
        var inicio = partes[1] === '' ? Math.max(total - parseInt(partes[2], 10), 0) : parseInt(partes[1], 10);
        var fim = partes[1] === '' || partes[2] === '' ? total - 1 : Math.min(parseInt(partes[2], 10), total - 1);
        if (inicio > fim || inicio >= total) {
            return new Response('', { status: 416, headers: { 'Content-Range': 'bytes */' + total } });
        }
        return new Response(buffer.slice(inicio, fim + 1), {
            status: 206,
            headers: {
                'Content-Type': resposta.headers.get('Content-Type') || 'application/octet-stream',
                'Content-Length': String(fim - inicio + 1),
                'Content-Range': 'bytes ' + inicio + '-' + fim + '/' + total,
                'Accept-Ranges': 'bytes'
            }
        });
    });
}

/** Mídias: cache primeiro. Na primeira vez baixa o arquivo inteiro (sem Range), guarda e responde. */
function midia(requisicao) {
    var chave = new Request(requisicao.url); // sem o cabeçalho Range: a chave é a URL
    return caches.open(CACHE_MIDIA).then(function (cache) {
        return cache.match(chave).then(function (guardada) {
            if (guardada) { return fatiar(requisicao, guardada); }
            return fetch(chave).then(function (resposta) {
                if (resposta && resposta.ok) {
                    cache.put(chave, resposta.clone());
                    return fatiar(requisicao, resposta);
                }
                return resposta;
            }, function () { return new Response('', { status: 504, statusText: 'Sem conexão' }); });
        });
    });
}

self.addEventListener('fetch', function (evento) {
    var requisicao = evento.request;
    if (requisicao.method !== 'GET') { return; }

    var url = new URL(requisicao.url);
    if (url.origin !== self.location.origin) { return; }
    var caminho = url.pathname;

    if (/^\/tv\/[A-Za-z0-9]{12}\/media\/[a-f0-9]{32}\.(jpg|png|webp|mp4)$/.test(caminho)) {
        evento.respondWith(midia(requisicao));
    } else if (/^\/tv\/[A-Za-z0-9]{12}\/state$/.test(caminho)) {
        evento.respondWith(redePrimeiro(requisicao, CACHE_ESTADO));
    } else if (/^\/tv\/[A-Za-z0-9]{12}\/(logo|tema\.css)$/.test(caminho)) {
        evento.respondWith(redePrimeiro(requisicao, CACHE_ASSETS));
    } else if (/^\/tv\/[A-Za-z0-9]{12}\/?$/.test(caminho)) {
        evento.respondWith(redePrimeiro(requisicao, CACHE_PAGINAS));
    } else if (caminho.indexOf('/assets/') === 0) {
        evento.respondWith(copiaPrimeiro(requisicao, CACHE_ASSETS));
    }
    // Qualquer outra coisa (painel, login...) nem passa por aqui: o escopo é /tv/.
});
