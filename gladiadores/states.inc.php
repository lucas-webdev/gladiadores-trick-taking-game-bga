<?php

/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * Gladiadores implementation : © <Your name here> <Your email address here>
 *
 * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
 * See http://en.boardgamearena.com/#!doc/Studio for more information.
 * -----
 *
 * states.inc.php
 *
 * Gladiadores game states description
 *
 */

use Bga\GameFramework\GameStateBuilder;
use Bga\GameFramework\StateType;

$machinestates = [

    // Estado inicial: configuração do jogo → preparar primeira mão
    1 => GameStateBuilder::gameSetup(10)->build(),

    // Distribui cartas para nova rodada
    10 => GameStateBuilder::create()
        ->name('stNewHand')
        ->description('')
        ->type(StateType::GAME)
        ->action('stNewHand')
        ->transitions([
            'next' => 20 // vai para líder do primeiro truque
        ])
        ->build(),

    // Líder joga a primeira carta da batalha
    20 => GameStateBuilder::create()
        ->name('trickLead')
        ->description(clienttranslate('${actplayer} deve liderar a batalha'))
        ->descriptionmyturn(clienttranslate('${you} deve liderar a batalha'))
        ->type(StateType::ACTIVE_PLAYER)
        ->args('argTrickLead')
        ->possibleactions(['actPlayCard'])
        ->transitions([
            // depois que o líder joga, vamos para um estado GAME que avança o jogador
            'advance' => 22,
            'resolve' => 30, // (proteção caso mesa 2/bug)
        ])
        ->build(),

    // Jogadores seguintes jogam seguindo o naipe ou usando leão/arma
    21 => GameStateBuilder::create()
        ->name('trickFollow')
        ->description(clienttranslate('${actplayer} deve jogar uma carta seguindo as regras'))
        ->descriptionmyturn(clienttranslate('${you} deve jogar uma carta seguindo as regras'))
        ->type(StateType::ACTIVE_PLAYER)
        ->args('argTrickFollow')
        ->possibleactions(['actPlayCard'])
        ->transitions([
            'advance' => 22,   // próximo jogador na mesma batalha
            'resolve' => 30,   // todos jogaram → resolver batalha
        ])
        ->build(),

    22 => GameStateBuilder::create()
        ->name('advancePlayer')
        ->description('')
        ->type(StateType::GAME)
        ->action('stAdvancePlayer')
        ->transitions([
            'follow' => 21, // volta para seguir o truque
        ])
        ->build(),

    // Resolução da batalha
    30 => GameStateBuilder::create()
        ->name('trickResolve')
        ->description('')
        ->type(StateType::GAME)
        ->action('stTrickResolve')
        ->transitions([
            'next' => 40
        ])
        ->build(),

    // Próximo truque ou fim da rodada
    40 => GameStateBuilder::create()
        ->name('nextPlayerOrNextTrick')
        ->description('')
        ->type(StateType::GAME)
        ->action('stNextPlayerOrNextTrick')
        ->transitions([
            'trick' => 20, // próxima batalha
            'round' => 50  // fim de rodada
        ])
        ->build(),

    // Contagem de pontos no fim da rodada
    50 => GameStateBuilder::create()
        ->name('endRoundScoring')
        ->description('')
        ->type(StateType::GAME)
        ->action('stEndRoundScoring')
        ->transitions([
            'end' => 98,
            'next' => 60
        ])
        ->build(),

    // Preparar próxima rodada
    60 => GameStateBuilder::create()
        ->name('prepareNextRound')
        ->description('')
        ->type(StateType::GAME)
        ->action('stPrepareNextRound')
        ->transitions([
            'next' => 10
        ])
        ->build(),

    // Estado final
    98 => GameStateBuilder::endScore()->build(),
];
