# Gladiadores - Board Game Arena

Implementacao digital do jogo de cartas **Gladiadores** para a plataforma [Board Game Arena](https://boardgamearena.com/), desenvolvido com o [BGA Studio](https://studio.boardgamearena.com/).

**Gladiadores** e um jogo de vazas (trick-taking) competitivo para 3 ou 4 jogadores, dos designers Enyo Saldanha e Lucas Medeiros, publicado pela **Calamity Games**. Os jogadores disputam batalhas com cartas de combate, leoes e armas danificadas, acumulando pontos de gloria ao longo de multiplas rodadas.

- BGG: https://boardgamegeek.com/boardgame/452795

## Estrutura do projeto

```
gladiadores/
├── modules/php/
│   └── Game.php              # Logica do jogo (server-side)
├── states.inc.php             # Maquina de estados
├── dbmodel.sql                # Schema do banco (card, glory_track)
├── gameinfos.inc.php          # Metadados para BGA
├── gameoptions.json           # Opcoes de mesa
├── stats.json                 # Definicao de estatisticas
├── gladiadores.js             # Interface do cliente
├── gladiadores.css            # Estilos e mapeamento de sprites
├── img/
│   ├── cards_sprite.jpg       # Sprite sheet das cartas (1800x1500, grid 10x6)
│   └── board_sprite.jpg       # Sprite do tabuleiro de gloria
└── GLD_Manual.pdf             # Manual oficial
```

## Arquitetura

### Server-side (PHP)

`Game.php` concentra toda a logica: setup, distribuicao de cartas, resolucao de vazas, pontuacao (lados A e B) e gerenciamento de estado. Os estados sao definidos em `states.inc.php` usando `GameStateBuilder`.

### Client-side (JS)

`gladiadores.js` gerencia a UI: renderizacao de cartas via sprite sheet, destaque de cartas jogaveis, dorso de adversarios, arena de batalha e notificacoes.

### Banco de dados

## Desenvolvimento

### Pre-requisitos

- Conta de desenvolvedor no [BGA Studio](https://studio.boardgamearena.com/)
- Acesso SFTP ao servidor de desenvolvimento

### Deploy

Enviar o conteudo da pasta `gladiadores/` para o diretorio do projeto no servidor BGA Studio via SFTP.

### Framework

O projeto utiliza o **novo framework BGA** com:
- Namespace `Bga\Games\Gladiadores`
- `GameStateBuilder` e `StateType` para definicao de estados
- `bgaPerformAction` no client-side
- `bgaSetupPromiseNotifications` para notificacoes async

## Licenca

Desenvolvido para uso exclusivo na plataforma Board Game Arena. Veja [LICENCE_BGA](gladiadores/LICENCE_BGA).
