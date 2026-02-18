<?php

namespace Bga\Games\Gladiadores;

require_once(APP_GAMEMODULE_PATH . 'module/table/table.game.php');

class Game extends \Table
{
    // ===== Constantes oficiais =====
    private const SUITS = ['T', 'M', 'G', 'X']; // Tridente, Mangual, Gládio, Machado
    private const LION  = 'N';
    private const QTD_LEOES = 4;
    private const QTD_ARMAS_DANIFICADAS = 6;
    private const CARTAS_COMBATE_POR_NAIPE = 10;
    private array $optionsCache = [];

    public function __construct()
    {
        parent::__construct();
        $this->initGameStateLabels([
            'board_side'   => 10, // 0=A, 1=B (também pode ser preenchido via opção)
            'round_number' => 11,
            'lead_suit'    => 12, // 0=none, 1..4 = SUITS idx
            'lion_count'   => 13,
            'next_leader'  => 14,
        ]);
    }


    // ===== Dados cliente =====
    protected function getAllDatas(): array
    {
        $res = [];
        $pid = (int)$this->getCurrentPlayerId();

        $res['players'] = $this->getCollectionFromDb(
            "SELECT player_id id, player_score score, player_name name, player_color color FROM player"
        );

        // Mão do jogador atual + assetCode para sprite GLxxx
        $hand = $this->getCollectionFromDb(
            "SELECT card_id, type, suit, value, dual_suits FROM card WHERE location='hand_{$pid}' ORDER BY location_arg"
        );
        foreach ($hand as &$c) {
            $c['assetCode'] = $this->mapAssetCode($c); // GL001..GL050
        }
        $res['hand'] = array_values($hand);

        $res['board_side']   = (int)$this->getGameStateValue('board_side');
        $res['round_number'] = (int)$this->getGameStateValue('round_number');
        $res['lead_suit_i']  = (int)$this->getGameStateValue('lead_suit');
        $res['lion_count']   = (int)$this->getGameStateValue('lion_count');

        return $res;
    }

    protected function getGameName(): string
    {
        return 'gladiadores';
    }

    protected function zombieTurn(array $state, int $active_player): void
    {
        if ($state['type'] === 'activeplayer') {
            $this->gamestate->nextState('zombiePass');
            return;
        }
        if ($state['type'] === 'multipleactiveplayer') {
            $this->gamestate->setPlayerNonMultiactive($active_player, '');
            return;
        }
        throw new \feException("Zombie mode not supported at this game state: '{$state['name']}'.");
    }

    // ===== Helper para opções (compatível com projetos que não têm getGameOptionValue) =====
    private function getOpt(string $key, int $default = 0): int
    {
        // Novo framework: getGameOptionValue('board_side')
        if (method_exists($this, 'getGameOptionValue')) {
            try {
                return (int)$this->getGameOptionValue($key);
            } catch (\Throwable $e) { /* fallback abaixo */
            }
        }
        // Fallback: usar game state label com mesmo nome, se existir
        try {
            return (int)$this->getGameStateValue($key);
        } catch (\Throwable $e) {
            return $default;
        }
    }

    // ===== Setup inicial =====
    protected function setupNewGame($players, $options = [])
    {
        $gameinfos = $this->getGameinfos();
        $colors    = $gameinfos['player_colors'];
        $vals      = [];

        $this->optionsCache = is_array($options) ? $options : [];



        foreach ($players as $player_id => $player) {
            $vals[] = vsprintf("('%s','%s','%s','%s','%s')", [
                $player_id,
                array_shift($colors),
                $player['player_canal'],
                addslashes($player['player_name']),
                addslashes($player['player_avatar']),
            ]);
        }

        static::DbQuery("INSERT INTO player (player_id, player_color, player_canal, player_name, player_avatar) VALUES " . implode(',', $vals));

        $this->reattributeColorsBasedOnPreferences($players, $gameinfos['player_colors']);
        $this->reloadPlayersBasicInfos();

        // Lê opção board_side de forma robusta
        $boardSide = isset($options['board_side'])
            ? (int) $options['board_side']
            : $this->getGameOptionValue('board_side', 0);
        $this->setGameStateInitialValue('board_side', $boardSide);
        $this->setGameStateInitialValue('round_number', 1);
        $this->setGameStateInitialValue('lead_suit', 0);
        $this->setGameStateInitialValue('lion_count', 0);

        $this->activeNextPlayer();
    }

    // ===== Nova mão =====
    public function stNewHand(): void
    {
        $playerIds   = array_map('intval', array_keys($this->loadPlayersBasicInfos()));
        $playerCount = count($playerIds);

        $this->buildDeck($playerCount);
        $this->shuffleDraw();
        $this->setAsideTwo();

        $cardsPerPlayer = ($playerCount === 3) ? 13 : 12;
        foreach ($playerIds as $pid) {
            $this->dealTo($pid, $cardsPerPlayer);
        }

        $this->setGameStateValue('lead_suit', 0);
        $this->setGameStateValue('lion_count', 0);

        $this->notifyAllPlayers('newHand', clienttranslate('Nova mão distribuída'), [
            'cardsPerPlayer' => $cardsPerPlayer,
        ]);

        $this->gamestate->nextState('next'); // → trickLead
    }

    // ===== Resolução do truque =====
    public function stTrickResolve(): void
    {
        $played  = $this->getArenaCardsOrdered();
        $winner  = $this->computeTrickWinner($played);

        $this->applyDamagedQueue($played, $winner);
        $collected = $this->collectForWinner($played, $winner);

        $this->notifyAllPlayers('trickWon', clienttranslate('${player_name} vence a batalha'), [
            'player_id'   => $winner,
            'player_name' => $this->getPlayerNameById($winner),
            'cards'       => $collected
        ]);

        $this->clearArena();
        $this->setGameStateValue('lead_suit', 0);
        $this->setGameStateValue('lion_count', 0);

        $this->setGameStateValue('next_leader', (int)$winner);
        $this->gamestate->nextState('next'); // → nextPlayerOrNextTrick
    }

    public function stNextPlayerOrNextTrick(): void
    {
        $next = (int)$this->getGameStateValue('next_leader');
        if ($next > 0) {
            // NUNCA use changeActivePlayer aqui: estamos num GAME state, use setActivePlayer
            $this->gamestate->setActivePlayer($next);
            $this->setGameStateValue('next_leader', 0);
        }

        if ($this->allPlayersHaveAtLeast(2)) {
            $this->gamestate->nextState('trick'); // → state 20 (trickLead)
            return;
        }
        $this->discardLastCards();
        $this->gamestate->nextState('round'); // → state 50 (endRoundScoring)
    }


    public function stEndRoundScoring(): void
    {
        if ((int)$this->getGameStateValue('board_side') === 0) {
            $this->scoreSideA();
        } else {
            $this->scoreSideB();
        }

        if ($this->isEndOfGame()) {
            $this->gamestate->nextState('end');
        } else {
            $this->gamestate->nextState('next'); // → prepareNextRound
        }
    }

    public function stPrepareNextRound(): void
    {
        $this->passFirstPlayerMarker();
        $this->rebuildFromAllPiles();
        $this->incGameStateValue('round_number', 1);
        $this->gamestate->nextState('next'); // → stNewHand
    }

    // ===== Auxiliares de arena =====
    private function getArenaCardsOrdered(): array
    {
        $rows = self::getObjectListFromDB(
            "SELECT card_id,type,suit,value,dual_suits,location,location_arg
             FROM card WHERE location LIKE 'arena_%' ORDER BY location_arg ASC"
        );
        foreach ($rows as &$r) {
            $r['player_id'] = (int)substr($r['location'], strlen('arena_'));
        }
        return $rows;
    }

    private function getLeadSuitChar(): ?string
    {
        $i = (int)$this->getGameStateValue('lead_suit');
        if ($i === 0) return null;
        return self::SUITS[$i - 1] ?? null;
    }

    private function compareLeaderCards(array $a, array $b): int
    {
        $av = (int)$a['value'];
        $bv = (int)$b['value'];
        if ($av === 1 && $bv === 10) return 1;
        if ($av === 10 && $bv === 1) return -1;
        return $av <=> $bv;
    }

    private function computeTrickWinner(array $played): int
    {
        // 1) Leões: vence o último leão
        $idxLastLion = -1;
        foreach ($played as $i => $c) {
            if ($c['type'] === 'lion') {
                $idxLastLion = $i;
            }
        }
        if ($idxLastLion >= 0) return (int)$played[$idxLastLion]['player_id'];

        $leader = $this->getLeadSuitChar();

        // 2) Maior carta de COMBATE do naipe líder (1 vence 10)
        $leaderCombats = array_values(array_filter($played, fn($c) => $c['type'] === 'combat' && $c['suit'] === $leader));
        if ($leaderCombats) {
            usort($leaderCombats, fn($a, $b) => $this->compareLeaderCards($a, $b));
            $card = end($leaderCombats);
            return (int)$card['player_id'];
        }

        // 3) Se ninguém jogou combate do líder: última ARMA danificada declarada no líder
        $leaderDamaged = array_values(array_filter($played, fn($c) => $c['type'] === 'damaged' && $c['suit'] === $leader));
        if ($leaderDamaged) {
            $last = end($leaderDamaged);
            return (int)$last['player_id'];
        }

        // 4) Caso líder tenha sido definido por arma e ninguém seguiu: quem definiu o líder (primeira arma)
        $anyDamaged = array_values(array_filter($played, fn($c) => $c['type'] === 'damaged'));
        if ($anyDamaged) {
            return (int)reset($anyDamaged)['player_id'];
        }

        // Fallback: primeira carta
        return (int)reset($played)['player_id'];
    }

    private function applyDamagedQueue(array $played, int $winner): void
    {
        // Ordem de resolução = ordem jogada
        $queue = array_values(array_filter($played, fn($c) => $c['type'] === 'damaged'));

        foreach ($queue as $dmg) {
            $suit = $dmg['suit']; // naipe declarado
            // alvo 1: menor carta de combate desse naipe na ARENA
            $arenaTargets = array_values(array_filter($played, fn($c) => $c['type'] === 'combat' && $c['suit'] === $suit));
            if ($arenaTargets) {
                usort($arenaTargets, fn($a, $b) => (int)$a['value'] <=> (int)$b['value']);
                $target = reset($arenaTargets);
                self::DbQuery("UPDATE card SET location='discard', location_arg=0 WHERE card_id=" . (int)$target['card_id']);
                foreach ($played as $k => $pc) {
                    if ((int)$pc['card_id'] === (int)$target['card_id']) {
                        unset($played[$k]);
                        break;
                    }
                }
            } else {
                // alvo 2: menor carta desse naipe na ÁREA do vencedor
                $loc = "area_" . $winner;
                $row = self::getObjectFromDB(
                    "SELECT card_id FROM card WHERE location='{$loc}' AND type='combat' AND suit='" . addslashes($suit) . "' ORDER BY value ASC, card_id ASC LIMIT 1"
                );
                if ($row) self::DbQuery("UPDATE card SET location='discard', location_arg=0 WHERE card_id=" . (int)$row['card_id']);
            }
        }

        // armas danificadas sempre descartadas
        self::DbQuery("UPDATE card SET location='discard', location_arg=0 WHERE type='damaged' AND location LIKE 'arena_%'");
    }

    private function collectForWinner(array $played, int $winner): array
    {
        $leader  = $this->getLeadSuitChar();
        $hasLion = ((int)$this->getGameStateValue('lion_count')) > 0;

        $leaderCombats = array_values(array_filter($played, fn($c) => $c['type'] === 'combat' && $c['suit'] === $leader));
        $allCombats    = array_values(array_filter($played, fn($c) => $c['type'] === 'combat'));

        $choice = null;
        if ($hasLion || count($leaderCombats) < count($allCombats)) {
            $others = array_values(array_filter($allCombats, fn($c) => $c['suit'] !== $leader));
            if ($others) {
                usort($others, fn($a, $b) => (int)$a['value'] <=> (int)$b['value']);
                $choice = end($others);
            }
        }

        $movedIds = [];
        $area     = "area_" . $winner;

        foreach ($leaderCombats as $c) {
            self::DbQuery("UPDATE card SET location='{$area}', location_arg=value WHERE card_id=" . (int)$c['card_id']);
            $movedIds[] = (int)$c['card_id'];
        }
        if ($choice) {
            self::DbQuery("UPDATE card SET location='{$area}', location_arg=value WHERE card_id=" . (int)$choice['card_id']);
            $movedIds[] = (int)$choice['card_id'];
        }

        $idsStr = $movedIds ? implode(',', $movedIds) : '0';
        self::DbQuery("UPDATE card SET location='discard', location_arg=0 WHERE location LIKE 'arena_%' AND card_id NOT IN ($idsStr)");

        if (!$movedIds) return [];
        return array_values(self::getObjectListFromDB("SELECT card_id,type,suit,value FROM card WHERE card_id IN ($idsStr)"));
    }

    private function clearArena(): void
    {
        self::DbQuery("UPDATE card SET location='discard', location_arg=0 WHERE location LIKE 'arena_%'");
    }

    private function allPlayersHaveAtLeast(int $n): bool
    {
        foreach ($this->loadPlayersBasicInfos() as $pid => $_) {
            $loc = "hand_" . (int)$pid;
            $cnt = (int)self::getUniqueValueFromDB("SELECT COUNT(*) FROM card WHERE location='{$loc}'");
            if ($cnt < $n) return false;
        }
        return true;
    }

    private function discardLastCards(): void
    {
        foreach ($this->loadPlayersBasicInfos() as $pid => $_) {
            $loc = "hand_" . (int)$pid;
            $row = self::getObjectFromDB("SELECT card_id FROM card WHERE location='{$loc}' ORDER BY location_arg DESC LIMIT 1");
            if ($row) self::DbQuery("UPDATE card SET location='discard', location_arg=0 WHERE card_id=" . (int)$row['card_id']);
        }
    }

    // ===== Pontuação =====
    private function scoreSideA(): void
    {
        $pids = array_map('intval', array_keys($this->loadPlayersBasicInfos()));
        $counts = [];
        foreach ($pids as $pid) {
            $loc = "area_" . $pid;
            foreach (self::SUITS as $s) {
                $counts[$pid][$s] = (int)self::getUniqueValueFromDB(
                    "SELECT COUNT(*) FROM card WHERE location='{$loc}' AND type='combat' AND suit='" . addslashes($s) . "'"
                );
            }
        }

        foreach (self::SUITS as $s) {
            $max = 0;
            foreach ($pids as $pid) {
                $max = max($max, $counts[$pid][$s]);
            }
            foreach ($pids as $pid) {
                $cur = (int)self::getUniqueValueFromDB("SELECT player_score FROM player WHERE player_id=" . $pid);
                if ($counts[$pid][$s] === 0) {
                    // sem mudança
                } elseif ($counts[$pid][$s] === $max && $max > 0) {
                    $cur += 1;
                } else {
                    $diff = $max - $counts[$pid][$s];
                    $cur += ($diff >= 4 ? -2 : -1);
                }
                self::DbQuery("UPDATE player SET player_score=" . $cur . " WHERE player_id=" . $pid);
                $this->incStat(max(0, $cur), 'points_side_a', $pid);
            }
        }
    }

    private function scoreSideB(): void
    {
        $pids = array_map('intval', array_keys($this->loadPlayersBasicInfos()));
        $roundPoints = [];

        foreach ($pids as $pid) {
            $loc = "area_" . $pid;
            $bySuit = [];
            foreach (self::SUITS as $s) {
                $bySuit[$s] = (int)self::getUniqueValueFromDB(
                    "SELECT COUNT(*) FROM card WHERE location='{$loc}' AND type='combat' AND suit='" . addslashes($s) . "'"
                );
            }
            $vals = array_values($bySuit);
            if (array_sum($vals) === 0) {
                $roundPoints[$pid] = null;
                continue;
            }

            $max = max($vals);
            $min = min($vals);
            if ($max === $min) {
                $roundPoints[$pid] = array_sum($vals);
            } else {
                $best  = array_sum(array_filter($vals, fn($v) => $v === $max));
                $worst = array_sum(array_filter($vals, fn($v) => $v === $min));
                $roundPoints[$pid] = $best - $worst;
            }
        }

        $maxPts = max(array_map(fn($v) => $v ?? PHP_INT_MIN, $roundPoints));
        foreach ($roundPoints as $pid => $pts) {
            if ($pts === null) $roundPoints[$pid] = $maxPts;
        }

        foreach ($roundPoints as $pid => $delta) {
            $cur = (int)self::getUniqueValueFromDB("SELECT player_score FROM player WHERE player_id=" . $pid);
            $new = $cur + (int)$delta;
            self::DbQuery("UPDATE player SET player_score=" . $new . " WHERE player_id=" . $pid);
            $this->incStat((int)$delta, 'points_side_b', $pid);
        }
    }

    private function isEndOfGame(): bool
    {
        $players = count($this->loadPlayersBasicInfos());
        $sideB   = (int)$this->getGameStateValue('board_side') === 1;

        if ($sideB) {
            $target = ($players === 3 ? 15 : 20);
            $max = (int)self::getUniqueValueFromDB("SELECT MAX(player_score) FROM player");
            if ($max >= $target) return true;
            $round = (int)$this->getGameStateValue('round_number');
            return $round > ($players === 3 ? 3 : 4);
        }

        $round = (int)$this->getGameStateValue('round_number');
        return $round > ($players === 3 ? 3 : 4);
    }

    private function passFirstPlayerMarker(): void
    {
        $this->activeNextPlayer();
    }

    private function rebuildFromAllPiles(): void
    {
        self::DbQuery("UPDATE card SET location='draw', location_arg=0 WHERE location LIKE 'area_%' OR location='discard' OR location='aside' OR location LIKE 'arena_%'");
        $this->shuffleDraw();
    }

    // ===== Baralho =====
    private function buildDeck(int $playerCount): void
    {
        self::DbQuery("DELETE FROM card");

        foreach (self::SUITS as $s) {
            for ($v = 1; $v <= self::CARTAS_COMBATE_POR_NAIPE; $v++) {
                self::DbQuery(vsprintf(
                    "INSERT INTO card(type,suit,value,location,location_arg) VALUES ('combat','%s',%d,'draw',0)",
                    [$s, $v]
                ));
            }
        }
        for ($i = 0; $i < self::QTD_LEOES; $i++) {
            self::DbQuery("INSERT INTO card(type,suit,value,location,location_arg) VALUES ('lion','N',11,'draw',0)");
        }
        $pairs = [['T', 'M'], ['T', 'G'], ['T', 'X'], ['M', 'G'], ['M', 'X'], ['G', 'X']];
        foreach ($pairs as $p) {
            self::DbQuery(vsprintf(
                "INSERT INTO card(type,suit,value,dual_suits,location,location_arg) VALUES ('damaged',NULL,0,'%s|%s','draw',0)",
                $p
            ));
        }
        if ($playerCount === 3) {
            self::DbQuery("DELETE FROM card WHERE type='combat' AND value IN (2,3) AND location='draw'");
            $row = $this->getObjectFromDB("SELECT card_id FROM card WHERE type='lion' AND location='draw' ORDER BY RAND() LIMIT 1");
            if ($row) {
                self::DbQuery("DELETE FROM card WHERE card_id=" . (int)$row['card_id']);
            }
        }
    }

    private function shuffleDraw(): void
    {
        $rows = $this->getObjectListFromDB("SELECT card_id FROM card WHERE location='draw' ORDER BY RAND()");
        $i = 1;
        foreach ($rows as $r) {
            self::DbQuery("UPDATE card SET location_arg={$i} WHERE card_id=" . (int)$r['card_id']);
            $i++;
        }
    }

    private function setAsideTwo(): void
    {
        $rows = $this->getObjectListFromDB("SELECT card_id FROM card WHERE location='draw' ORDER BY location_arg ASC LIMIT 2");
        foreach ($rows as $r) {
            self::DbQuery("UPDATE card SET location='aside', location_arg=0 WHERE card_id=" . (int)$r['card_id']);
        }
    }

    private function dealTo(int $playerId, int $n): void
    {
        $rows = $this->getObjectListFromDB("SELECT card_id FROM card WHERE location='draw' ORDER BY location_arg ASC LIMIT " . $n);
        $i = 1;
        foreach ($rows as $r) {
            $loc = "hand_" . $playerId;
            self::DbQuery("UPDATE card SET location='{$loc}', location_arg={$i} WHERE card_id=" . (int)$r['card_id']);
            $i++;
        }
    }

    // ===== Mapeamento GLxxx para sprites =====
    /**
     * Ordem definida para o sprite:
     * GL001..GL010 = T 1..10
     * GL011..GL020 = M 1..10
     * GL021..GL030 = G 1..10
     * GL031..GL040 = X 1..10
     * GL041..GL044 = 4 Leões
     * GL045..GL050 = 6 Armas Danificadas
     */
    private function mapAssetCode(array $card): string
    {
        if ($card['type'] === 'combat') {
            $sIndex = array_search($card['suit'], self::SUITS, true); // 0..3
            $v = max(1, min(10, (int)$card['value']));
            $num = $sIndex * 10 + $v; // 1..40
            return 'GL' . str_pad((string)$num, 3, '0', STR_PAD_LEFT);
        }

        if ($card['type'] === 'damaged') {
            $norm = strtoupper(str_replace(['/', '-', ','], '|', $card['dual_suits'] ?? ''));
            $map = [
                'X|G' => 41,
                'X|M' => 42,
                'X|T' => 43,
                'G|X' => 44,
                'G|T' => 45,
                'M|T' => 46,
            ];
            $num = $map[$norm] ?? 41;
            return 'GL' . str_pad((string)$num, 3, '0', STR_PAD_LEFT);
        }

        if ($card['type'] === 'lion') {
            // 47..50 (variante visual opcional)
            $num = 47 + (($card['card_id'] ?? 0) % 4);
            return 'GL' . str_pad((string)$num, 3, '0', STR_PAD_LEFT);
        }

        return 'GL001';
    }



    private function getGameOptionValue(string $key, int $default = 0): int
    {
        // 1) Se já está em um global (initGameStateLabels), use-o
        try {
            return (int) $this->getGameStateValue($key);
        } catch (\Throwable $e) {
            // segue
        }

        // 2) Se foi passado no setup, use o cache
        if (isset($this->optionsCache[$key])) {
            return (int) $this->optionsCache[$key];
        }

        // 3) Padrão
        return $default;
    }

    // ===== Args de estados de truque =====
    public function argTrickLead(): array
    {
        return $this->argCommonPlayable();
    }

    public function argTrickFollow(): array
    {
        return $this->argCommonPlayable();
    }

    private function argCommonPlayable(): array
    {
        $pid = (int)$this->getActivePlayerId();
        $lead = $this->getLeadSuitChar(); // null se não definido
        $hand = self::getObjectListFromDB(
            "SELECT card_id,type,suit,dual_suits FROM card WHERE location='hand_{$pid}' ORDER BY location_arg"
        );

        // Se não há naipe líder, tudo é jogável
        if ($lead === null) {
            return ['playableCardsIds' => array_map(fn($c) => (int)$c['card_id'], $hand)];
        }

        // Tem combate do naipe líder?
        $hasLeaderCombat = false;
        foreach ($hand as $c) {
            if ($c['type'] === 'combat' && $c['suit'] === $lead) {
                $hasLeaderCombat = true;
                break;
            }
        }

        // Tem arma danificada que possa declarar o líder?
        $hasDamagedLeader = false;
        foreach ($hand as $c) {
            if ($c['type'] === 'damaged' && !empty($c['dual_suits'])) {
                [$a, $b] = explode('|', $c['dual_suits']);
                if ($a === $lead || $b === $lead) {
                    $hasDamagedLeader = true;
                    break;
                }
            }
        }

        $ids = [];
        if ($hasLeaderCombat || $hasDamagedLeader) {
            // Obrigado a "seguir": combate do líder ou arma danificada que cubra o líder
            foreach ($hand as $c) {
                if ($c['type'] === 'combat' && $c['suit'] === $lead) {
                    $ids[] = (int)$c['card_id'];
                }
                if ($c['type'] === 'damaged' && !empty($c['dual_suits'])) {
                    [$a, $b] = explode('|', $c['dual_suits']);
                    if ($a === $lead || $b === $lead) {
                        $ids[] = (int)$c['card_id'];
                    }
                }
            }
            // Se nada entrou por algum erro, libera toda mão como fallback seguro
            if (!$ids) $ids = array_map(fn($c) => (int)$c['card_id'], $hand);
        } else {
            // Não consegue seguir → qualquer carta é jogável
            $ids = array_map(fn($c) => (int)$c['card_id'], $hand);
        }

        return ['playableCardsIds' => $ids];
    }

    // ===== Ações do jogador =====
    public function actPlayCard(int $card_id, ?string $declaredSuit = null): void
    {
        $this->checkAction('actPlayCard');
        $pid = (int)$this->getActivePlayerId();

        // valida carta na mão
        $card = self::getObjectFromDB(
            "SELECT card_id,type,suit,value,dual_suits FROM card WHERE card_id=$card_id AND location='hand_{$pid}'"
        );
        if (!$card) throw new \BgaUserException('Carta inválida');

        // se arma danificada, validar naipe declarado
        if ($card['type'] === 'damaged') {
            if (!$declaredSuit) throw new \BgaUserException('Declare o naipe');
            if (!in_array($declaredSuit, self::SUITS, true)) throw new \BgaUserException('Naipe inválido');
            [$a, $b] = explode('|', $card['dual_suits']);
            if ($declaredSuit !== $a && $declaredSuit !== $b) throw new \BgaUserException('Arma não cobre este naipe');
            // gravar naipe declarado na própria carta
            self::DbQuery("UPDATE card SET suit='" . addslashes($declaredSuit) . "' WHERE card_id=" . $card_id);
            $card['suit'] = $declaredSuit;
        }

        // define líder se ainda não houver
        if ((int)$this->getGameStateValue('lead_suit') === 0 && $card['type'] !== 'lion') {
            $idx = array_search($card['suit'], self::SUITS, true);
            if ($idx !== false) $this->setGameStateValue('lead_suit', $idx + 1);
        }
        if ($card['type'] === 'lion') $this->incGameStateValue('lion_count', 1);

        // mover para arena na ordem
        $order = (int)self::getUniqueValueFromDB("SELECT IFNULL(MAX(location_arg),0)+1 FROM card WHERE location LIKE 'arena_%'");
        self::DbQuery("UPDATE card SET location='arena_{$pid}', location_arg={$order} WHERE card_id=" . $card_id);

        // notificar
        $this->notifyAllPlayers('cardPlayed', clienttranslate('${player_name} joga uma carta'), [
            'player_id'   => $pid,
            'player_name' => $this->getActivePlayerName(),
            'card'        => $card,
        ]);

        // último da batalha?
        $players = count($this->loadPlayersBasicInfos());
        $inArena = (int) self::getUniqueValueFromDB(
            "SELECT COUNT(DISTINCT SUBSTRING_INDEX(location,'_',-1)) FROM card WHERE location LIKE 'arena_%'"
        );

        if ($inArena >= $players) {
            // última carta da batalha → resolver
            $this->gamestate->nextState('resolve'); // (→ state 30)
        } else {
            // ainda falta gente jogar → NÃO troque aqui o ativo
            $this->gamestate->nextState('advance'); // (→ state 22)
        }
    }

    public function stAdvancePlayer(): void
    {
        // Apenas passa a vez para o próximo sentando-se na mesa
        $this->activeNextPlayer();

        // Volta para o estado de seguir o truque
        $this->gamestate->nextState('follow'); // (→ state 21)
    }

    public function actPass(): void
    {
        $this->checkAction('actPass');
        $pid = (int)$this->getActivePlayerId();
        $this->notifyAllPlayers('pass', clienttranslate('${player_name} passa'), [
            'player_id' => $pid,
            'player_name' => $this->getActivePlayerName()
        ]);
        $this->activeNextPlayer();
        $this->gamestate->nextState('pass');              // transição definida no states
    }
}
