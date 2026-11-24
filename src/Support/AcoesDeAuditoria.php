<?php

declare(strict_types=1);

namespace App\Support;

/** Ações registradas na auditoria e o texto que aparece para o administrador. */
final class AcoesDeAuditoria
{
    public const ROTULOS = [
        'login.entrou' => 'Entrou no sistema',
        'senha.alterada' => 'Trocou a própria senha',
        'senha.recuperacao_pedida' => 'Pediu a recuperação de senha',
        'senha.redefinida' => 'Redefiniu a senha pelo e-mail',
        'usuario.criado' => 'Criou um usuário',
        'usuario.atualizado' => 'Alterou um usuário',
        'usuario.excluido' => 'Excluiu um usuário',
        'condominio.criado' => 'Criou um condomínio',
        'condominio.atualizado' => 'Alterou um condomínio',
        'condominio.excluido' => 'Excluiu um condomínio',
        'dispositivo.criado' => 'Cadastrou um dispositivo',
        'dispositivo.atualizado' => 'Alterou um dispositivo',
        'dispositivo.token_trocado' => 'Trocou o token de um dispositivo',
        'dispositivo.excluido' => 'Excluiu um dispositivo',
        'dispositivo.pareado' => 'Pareou uma TV por código',
        'dispositivo.orientacao_alterada' => 'Mudou a orientação da tela de um dispositivo',
        'financeiro.atualizado' => 'Alterou a situação financeira de um condomínio',
        'condominio.bloqueado' => 'Bloqueou a exibição de um condomínio',
        'condominio.desbloqueado' => 'Desbloqueou a exibição de um condomínio',
        'aviso.criado' => 'Criou um aviso',
        'aviso.atualizado' => 'Alterou um aviso',
        'aviso.excluido' => 'Excluiu um aviso',
        'suporte.iniciado' => 'Entrou em modo suporte',
        'suporte.encerrado' => 'Saiu do modo suporte',
    ];

    /** Grupos para o filtro da tela (prefixo antes do ponto). */
    public const GRUPOS = [
        'login' => 'Entradas',
        'senha' => 'Senhas',
        'usuario' => 'Usuários',
        'condominio' => 'Condomínios',
        'financeiro' => 'Financeiro',
        'dispositivo' => 'Dispositivos',
        'aviso' => 'Avisos',
        'suporte' => 'Modo suporte',
    ];

    public static function rotulo(string $acao): string
    {
        return self::ROTULOS[$acao] ?? $acao;
    }
}
