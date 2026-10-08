define([
  "dojo",
  "dojo/_base/declare",
  "ebg/core/gamegui",
  "ebg/counter",
], function (dojo, declare) {
  const SUIT_NAMES      = { T: "Tridente", M: "Mangual",  G: "Gládio",  X: "Machado"  };
  const SUIT_ORDER      = ["T", "M", "G", "X"];
  const SUIT_COLORS      = { T: "#cc2222", M: "#1a1a1a",  G: "#2a8a4e", X: "#2255aa"  };
  // ícones: miniatura da carta (GL001/GL011/GL021/GL031) via sprite
  const SUIT_COLOR_NAMES = { T: "Vermelho", M: "Preto",   G: "Verde",   X: "Azul"     };

  return declare("bgagame.gladiadores", ebg.core.gamegui, {

    TRACK: {
      scale:  0.375,
      cellW:  75,
      cell0X: 80,
      rowY: { T: 105, M: 225, G: 345, X: 465 },
    },

    currentZoom: 1.0,

    constructor: function () {
      this.playerHand = {};
    },

    setup: function (gamedatas) {
      const area = this.getGameAreaElement();

      area.insertAdjacentHTML("beforeend", `
        <div id="gld-zoom-bar">
          <button id="gld-zoom-out" title="Menos zoom">−</button>
          <span id="gld-zoom-label">100%</span>
          <button id="gld-zoom-in" title="Mais zoom">+</button>
        </div>
        <div id="gld-scroll-wrap">
          <div id="gld-game-area">
            <div id="gld-top-row">
              <div id="gld-arena-col">
                <div class="gld-arena-title">Arena</div>
                <div id="arena" class="gld-arena"></div>
              </div>
              <div id="gld-board-col"></div>
            </div>
            <div id="gld-players-grid"></div>
          </div>
        </div>
        <div id="gld-hand-bar">
          <div class="gld-hand-bar-label">Sua mão</div>
          <div id="hand-${this.player_id}" class="gld-hand-inner"></div>
        </div>
      `);

      document.getElementById("gld-zoom-in").addEventListener("click", () =>
        this._applyZoom(this.currentZoom + 0.1));
      document.getElementById("gld-zoom-out").addEventListener("click", () =>
        this._applyZoom(this.currentZoom - 0.1));

      // Dimensionar arena para o número de jogadores (1 slot por jogador)
      const pcount = Object.keys(gamedatas.players).length;
      const arenaEl = document.getElementById("arena");
      if (arenaEl) {
        const arenaW = pcount * 175 + (pcount - 1) * 8 + 24; // slots + gaps + padding
        arenaEl.style.minWidth = arenaW + "px";
      }

      this._buildPlayerPanels(gamedatas.players);

      this.renderHand(gamedatas.hand || []);

      Object.entries(gamedatas.handCounts || {}).forEach(([pid, count]) =>
        this._setHandCount(pid, parseInt(count) || 0));

      (gamedatas.arena || []).forEach((c) => this.placeInArena(c, c.player_id));

      Object.entries(gamedatas.areas || {}).forEach(([pid, cards]) =>
        (cards || []).forEach((c) => this._addToSuitColumn(pid, c)));

      if (gamedatas.board_side === 0 && gamedatas.gloryTracks) {
        this.setupGloryTracks(gamedatas.players, gamedatas.gloryTracks);
      }

      this.setupNotifications();
    },

    /* ---------- layout ---------- */

    _buildPlayerPanels: function (players) {
      const grid = document.getElementById("gld-players-grid");
      if (!grid) return;
      grid.innerHTML = "";

      Object.values(players).forEach((p) => {
        const isSelf = String(p.id) === String(this.player_id);
        const suitCols = SUIT_ORDER.map((s) => `
          <div class="gld-suit-col">
            <div class="gld-suit-header" style="background:${SUIT_COLORS[s]}">
              <div class="gld-suit-icon gld-suit-icon-${s}"></div>
              <div class="gld-suit-name">${SUIT_NAMES[s]}</div>
              <div class="gld-suit-colorname">(${SUIT_COLOR_NAMES[s]})</div>
            </div>
            <div class="gld-suit-cards" id="area-cards-${p.id}-${s}"></div>
          </div>`).join("");

        grid.insertAdjacentHTML("beforeend", `
          <div class="gld-player-panel${isSelf ? " gld-self-panel" : ""}" id="panel-${p.id}">
            <div class="gld-panel-header">
              <span class="gld-player-name" style="color:#${p.color}">${p.name}</span>
              ${!isSelf ? `<span class="gld-hand-count" id="hand-count-${p.id}">
                <span class="gld-hc-icon"></span>
                <span id="hand-count-num-${p.id}">0</span>
              </span>` : ""}
            </div>
            <div class="gld-suit-grid">${suitCols}</div>
          </div>`);
      });
    },

    _applyZoom: function (level) {
      this.currentZoom = Math.max(0.5, Math.min(1.5, Math.round(level * 10) / 10));
      const area = document.getElementById("gld-game-area");
      if (area) {
        area.style.transform = `scale(${this.currentZoom})`;
        area.style.transformOrigin = "top left";
        const wrap = document.getElementById("gld-scroll-wrap");
        if (wrap) {
          wrap.style.minHeight = (area.offsetHeight * this.currentZoom) + "px";
          wrap.style.minWidth = (area.offsetWidth * this.currentZoom) + "px";
        }
      }
      const lbl = document.getElementById("gld-zoom-label");
      if (lbl) lbl.textContent = Math.round(this.currentZoom * 100) + "%";
    },

    /* ---------- contagem de mão dos adversários ---------- */

    _setHandCount: function (playerId, count) {
      if (String(playerId) === String(this.player_id)) return;
      const el = document.getElementById(`hand-count-num-${playerId}`);
      if (el) el.textContent = count;
    },

    _decrementHandCount: function (playerId) {
      if (String(playerId) === String(this.player_id)) return;
      const el = document.getElementById(`hand-count-num-${playerId}`);
      if (el) el.textContent = Math.max(0, (parseInt(el.textContent) || 1) - 1);
    },

    /* ---------- helpers de UI ---------- */

    renderHand: function (cards) {
      const container = document.getElementById(`hand-${this.player_id}`);
      if (!container) return;
      container.innerHTML = "";
      this.playerHand = {};
      cards.forEach((card) => {
        const node = this.createCardNode(card);
        node.addEventListener("click", () => this.onCardClick(card));
        container.appendChild(node);
        this.playerHand[card.card_id] = node;
      });
    },

    createCardNode: function (card) {
      const div = document.createElement("div");
      div.classList.add("gld-card", `gld-${card.type}`);
      div.dataset.id = card.card_id;
      div.dataset.type = card.type;
      if (card.suit) div.dataset.suit = card.suit;
      if (card.value != null) div.dataset.value = card.value;
      if (card.assetCode) {
        div.classList.add("gld-sprite", `gld-${card.assetCode}`);
      } else {
        div.innerHTML = `<span>${(card.suit || "") + (card.value || "")}</span>`;
      }
      return div;
    },

    createAreaCardNode: function (card) {
      const wrap = document.createElement("div");
      wrap.classList.add("gld-area-card-wrap");
      const node = this.createCardNode(card);
      node.classList.add("gld-area-card");
      wrap.appendChild(node);
      return wrap;
    },

    _addToSuitColumn: function (playerId, card) {
      const suit = card.suit;
      if (!suit || !SUIT_ORDER.includes(suit)) return;
      const container = document.getElementById(`area-cards-${playerId}-${suit}`);
      if (!container) return;
      container.appendChild(this.createAreaCardNode(card));
    },

    placeInArena: function (card, player_id) {
      const slot = document.createElement("div");
      slot.classList.add("gld-arena-slot");
      const playerName = this.gamedatas.players[player_id]?.name || player_id;
      slot.insertAdjacentHTML("beforeend",
        `<div class="gld-arena-label">${playerName}</div>`);
      slot.appendChild(this.createCardNode(card));
      document.getElementById("arena").appendChild(slot);
    },

    clearAllAreas: function () {
      document.querySelectorAll(".gld-suit-cards").forEach((el) => (el.innerHTML = ""));
    },

    /* ---------- trilhas de glória ---------- */

    setupGloryTracks: function (players, tracks) {
      const container = document.getElementById("gld-board-col");
      if (!container) return;
      container.innerHTML = "";

      const board = document.createElement("div");
      board.id = "gld-board";
      const layer = document.createElement("div");
      layer.id = "gld-track-layer";
      board.appendChild(layer);
      container.appendChild(board);

      const playerList = Object.values(players);
      SUIT_ORDER.forEach((suit) => {
        playerList.forEach((p, idx) => {
          const pos = tracks?.[p.id]?.[suit]?.position ?? 7;
          this._placeToken(layer, p, suit, pos, idx, playerList.length);
        });
      });
    },

    _placeToken: function (layer, player, suit, pos, idx, total) {
      const T = this.TRACK;
      const token = document.createElement("div");
      token.classList.add("gld-track-token");
      token.id = `gld-token-${player.id}-${suit}`;
      token.title = `${player.name} — ${SUIT_NAMES[suit]}: pos ${pos}`;
      token.style.background = "#" + player.color;
      token.style.left = (T.cell0X + pos * T.cellW) * T.scale + "px";
      token.style.top = T.rowY[suit] * T.scale + (idx - (total - 1) / 2) * 6 + "px";
      layer.appendChild(token);
    },

    updateGloryTracks: function (players, tracks) {
      const T = this.TRACK;
      const playerList = Object.values(players);
      const total = playerList.length;
      SUIT_ORDER.forEach((suit) => {
        playerList.forEach((p, idx) => {
          const pos = tracks?.[p.id]?.[suit]?.position;
          if (pos == null) return;
          const token = document.getElementById(`gld-token-${p.id}-${suit}`);
          if (!token) return;
          token.style.left = (T.cell0X + pos * T.cellW) * T.scale + "px";
          token.style.top = T.rowY[suit] * T.scale + (idx - (total - 1) / 2) * 6 + "px";
          token.title = `${p.name} — ${SUIT_NAMES[suit]}: pos ${pos}`;
        });
      });
    },

    /* ---------- estados ---------- */

    onEnteringState: function (stateName, args) {
      document.querySelectorAll(".gld-player-panel").forEach((p) =>
        p.classList.remove("gld-active-player"));

      if (stateName === "trickLead" || stateName === "trickFollow") {
        this.highlightPlayableCards(args.args);

        const ap = this.gamedatas?.gamestate?.active_player
          ?? this.gamedatas?.gamestate?.actplayer;
        if (ap) {
          const panel = document.getElementById(`panel-${ap}`);
          if (panel) panel.classList.add("gld-active-player");
        } else if (this.isCurrentPlayerActive()) {
          const panel = document.getElementById(`panel-${this.player_id}`);
          if (panel) panel.classList.add("gld-active-player");
        }
      }
    },

    onLeavingState: function (stateName) {
      document.querySelectorAll(".gld-card.gld-playable, .gld-card.gld-unplayable")
        .forEach((el) => {
          el.classList.remove("gld-playable");
          el.classList.remove("gld-unplayable");
        });
    },

    onUpdateActionButtons: function (stateName, args) {
      if (!this.isCurrentPlayerActive()) return;
    },

    highlightPlayableCards: function (args) {
      if (!this.isCurrentPlayerActive()) return;
      const playableSet = new Set((args?.playableCardsIds || []).map(String));
      Object.entries(this.playerHand).forEach(([cardId, node]) => {
        if (playableSet.has(String(cardId))) {
          node.classList.add("gld-playable");
          node.classList.remove("gld-unplayable");
        } else {
          node.classList.add("gld-unplayable");
          node.classList.remove("gld-playable");
        }
      });
    },

    /* ---------- ações ---------- */

    onCardClick: function (card) {
      if (!this.isCurrentPlayerActive()) return;
      const node = this.playerHand[card.card_id];
      if (node && node.classList.contains("gld-unplayable")) return;

      if (card.type === "damaged") {
        this.promptDamagedSuit(card).then((declaredSuit) => {
          if (!declaredSuit) return;
          this.bgaPerformAction("actPlayCard", { card_id: card.card_id, declaredSuit });
        });
        return;
      }
      this.bgaPerformAction("actPlayCard", { card_id: card.card_id });
    },

    promptDamagedSuit: function (card) {
      return new Promise((resolve) => {
        const suits = card.dual_suits && typeof card.dual_suits === "string"
          ? card.dual_suits.split("|")
          : SUIT_ORDER;
        this.removeActionButtons();
        suits.forEach((s) => {
          this.addActionButton(`btn_suit_${s}`, SUIT_NAMES[s] || s, () => {
            this.removeActionButtons();
            resolve(s);
          });
        });
        this.addActionButton("btn_suit_cancel", _("Cancelar"), () => {
          this.removeActionButtons();
          resolve(null);
        }, null, false, "gray");
      });
    },

    /* ---------- notificações ---------- */

    setupNotifications: function () {
      this.bgaSetupPromiseNotifications();
      this.notifqueue.setSynchronous("cardPlayed", 400);
      this.notifqueue.setSynchronous("trickWon", 800);
      this.notifqueue.setSynchronous("newHand", 500);
      this.notifqueue.setSynchronous("gloryTrackUpdate", 600);
    },

    notif_newHand: async function (args) {
      const arena = document.getElementById("arena");
      if (arena) arena.innerHTML = "";
      this.clearAllAreas();
      const handContainer = document.getElementById(`hand-${this.player_id}`);
      if (handContainer) handContainer.innerHTML = "";
      this.playerHand = {};
      const count = args.cardsPerPlayer || 12;
      Object.keys(this.gamedatas.players).forEach((pid) =>
        this._setHandCount(pid, count));
    },

    notif_newHandCards: async function (args) {
      this.renderHand(args.hand || []);
    },

    notif_cardPlayed: async function (args) {
      if (args.player_id == this.player_id) {
        const node = this.playerHand[args.card.card_id];
        if (node) node.remove();
        delete this.playerHand[args.card.card_id];
      } else {
        this._decrementHandCount(args.player_id);
      }
      this.placeInArena(args.card, args.player_id);
    },

    notif_trickWon: async function (args) {
      (args.cards || []).forEach((card) => this._addToSuitColumn(args.player_id, card));
      const arena = document.getElementById("arena");
      if (arena) arena.innerHTML = "";
    },

    notif_gloryTrackUpdate: async function (args) {
      this.updateGloryTracks(this.gamedatas.players, args.tracks);
    },
  });
});
