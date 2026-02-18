define([
  "dojo",
  "dojo/_base/declare",
  "ebg/core/gamegui",
  "ebg/counter",
], function (dojo, declare) {
  // Mapeamento de letras de naipe para nomes legíveis
  const SUIT_NAMES = {
    T: "Tridente",
    M: "Mangual",
    G: "Gládio",
    X: "Machado",
  };

  return declare("bgagame.gladiadores", ebg.core.gamegui, {
    constructor: function () {
      console.log("gladiadores constructor");
      this.playerHand = {}; // card_id -> DOM node
    },

    setup: function (gamedatas) {
      console.log("Starting game setup", gamedatas);

      // área raiz
      const area = this.getGameAreaElement();
      if (!document.getElementById("gld-game-area")) {
        area.insertAdjacentHTML(
          "beforeend",
          `
          <div id="gld-game-area">
            <div id="arena" class="gld-arena"></div>
            <div id="gld-player-tables"></div>
          </div>
        `
        );
      }

      // criar zonas por jogador
      const tables = document.getElementById("gld-player-tables");
      tables.innerHTML = "";
      Object.values(gamedatas.players).forEach((p) => {
        tables.insertAdjacentHTML(
          "beforeend",
          `
          <div id="player-table-${p.id}" class="gld-player-zone">
            <strong>${p.name}</strong>
            <div id="hand-${p.id}" class="gld-hand"></div>
            <div id="area-${p.id}" class="gld-area"></div>
          </div>
        `
        );
      });

      // renderizar minha mão
      this.renderHand(gamedatas.hand || []);

      // renderizar dorsos para adversários
      if (gamedatas.handCounts) {
        this.renderAllOpponentBacks(gamedatas.handCounts);
      }

      // renderizar cartas na arena (reconexão)
      if (Array.isArray(gamedatas.arena)) {
        gamedatas.arena.forEach((c) => {
          this.placeInArena(c, c.player_id);
        });
      }

      // renderizar áreas de jogo dos jogadores (reconexão)
      if (gamedatas.areas) {
        Object.keys(gamedatas.areas).forEach((pid) => {
          const cards = gamedatas.areas[pid];
          const areaEl = document.getElementById(`area-${pid}`);
          if (areaEl && Array.isArray(cards)) {
            cards.forEach((c) => {
              areaEl.appendChild(this.createCardNode(c));
            });
          }
        });
      }

      this.setupNotifications();
      console.log("Ending game setup");
    },

    /* ---------- helpers de UI ---------- */

    renderHand: function (cards) {
      const myId = this.player_id;
      const containerId = `hand-${myId}`;
      let container = document.getElementById(containerId);

      // fallback defensivo: cria container se algo falhou
      if (!container) {
        const myTable = document.getElementById(`player-table-${myId}`);
        if (myTable) {
          myTable.insertAdjacentHTML(
            "beforeend",
            `<div id="${containerId}" class="gld-hand"></div>`
          );
          container = document.getElementById(containerId);
        }
      }
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

    renderOpponentBacks: function (playerId, count) {
      if (String(playerId) === String(this.player_id)) return;
      const container = document.getElementById(`hand-${playerId}`);
      if (!container) return;
      container.innerHTML = "";
      for (let i = 0; i < count; i++) {
        const back = document.createElement("div");
        back.classList.add("gld-card-back");
        container.appendChild(back);
      }
    },

    renderAllOpponentBacks: function (handCounts) {
      Object.keys(handCounts).forEach((pid) => {
        this.renderOpponentBacks(pid, parseInt(handCounts[pid]) || 0);
      });
    },

    removeOneOpponentBack: function (playerId) {
      if (String(playerId) === String(this.player_id)) return;
      const container = document.getElementById(`hand-${playerId}`);
      if (!container) return;
      const back = container.querySelector(".gld-card-back");
      if (back) back.remove();
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
        div.innerHTML = `<span>${
          (card.suit || "") + (card.value || "")
        }</span>`;
      }
      return div;
    },

    placeInArena: function (card, player_id) {
      const slot = document.createElement("div");
      slot.classList.add("gld-arena-slot");
      const playerName =
        this.gamedatas.players[player_id]?.name || player_id;
      slot.insertAdjacentHTML(
        "beforeend",
        `<div class="gld-arena-label">${playerName}</div>`
      );
      slot.appendChild(this.createCardNode(card));
      document.getElementById("arena").appendChild(slot);
    },

    clearAllAreas: function () {
      Object.values(this.gamedatas.players).forEach((p) => {
        const areaEl = document.getElementById(`area-${p.id}`);
        if (areaEl) areaEl.innerHTML = "";
      });
    },

    /* ---------- estados ---------- */

    onEnteringState: function (stateName, args) {
      console.log("Entering state:", stateName, args);

      if (
        stateName === "trickLead" ||
        stateName === "trickFollow"
      ) {
        // Destacar cartas jogáveis
        this.highlightPlayableCards(args.args);
      }
    },

    onLeavingState: function (stateName) {
      console.log("Leaving state:", stateName);
      // Remover destaques
      document
        .querySelectorAll(".gld-card.gld-playable")
        .forEach((el) => el.classList.remove("gld-playable"));
      document
        .querySelectorAll(".gld-card.gld-unplayable")
        .forEach((el) => el.classList.remove("gld-unplayable"));
    },

    onUpdateActionButtons: function (stateName, args) {
      console.log("onUpdateActionButtons:", stateName, args);
      if (!this.isCurrentPlayerActive()) return;
      // A UI usa clique nas cartas. Sem botões extras por enquanto.
    },

    highlightPlayableCards: function (args) {
      if (!this.isCurrentPlayerActive()) return;
      const playable = args?.playableCardsIds || [];
      const playableSet = new Set(playable.map(String));

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

      // Verificar se a carta é jogável
      const node = this.playerHand[card.card_id];
      if (node && node.classList.contains("gld-unplayable")) return;

      // Se arma danificada, pedir naipe declarado
      if (card.type === "damaged") {
        this.promptDamagedSuit(card).then((declaredSuit) => {
          if (!declaredSuit) return;
          this.bgaPerformAction("actPlayCard", {
            card_id: card.card_id,
            declaredSuit,
          });
        });
        return;
      }

      // combate ou leão
      this.bgaPerformAction("actPlayCard", { card_id: card.card_id });
    },

    promptDamagedSuit: function (card) {
      return new Promise((resolve) => {
        const suitsFromCard =
          card.dual_suits && typeof card.dual_suits === "string"
            ? card.dual_suits.split("|")
            : ["T", "M", "G", "X"];

        // Criar botões no painel de ação do BGA
        this.removeActionButtons();
        suitsFromCard.forEach((s) => {
          const label = SUIT_NAMES[s] || s;
          this.addActionButton(
            `btn_suit_${s}`,
            label,
            () => {
              this.removeActionButtons();
              resolve(s);
            }
          );
        });
        this.addActionButton(
          "btn_suit_cancel",
          _("Cancelar"),
          () => {
            this.removeActionButtons();
            resolve(null);
          },
          null,
          false,
          "gray"
        );
      });
    },

    /* ---------- notificações ---------- */

    setupNotifications: function () {
      console.log("notifications subscriptions setup");
      this.bgaSetupPromiseNotifications();

      this.notifqueue.setSynchronous("cardPlayed", 400);
      this.notifqueue.setSynchronous("trickWon", 800);
      this.notifqueue.setSynchronous("newHand", 500);
    },

    notif_newHand: async function (args) {
      // Limpa arena e áreas de jogo para nova rodada
      const arena = document.getElementById("arena");
      if (arena) arena.innerHTML = "";
      this.clearAllAreas();

      // Renderiza dorsos para todos os adversários
      const count = args.cardsPerPlayer || 12;
      Object.values(this.gamedatas.players).forEach((p) => {
        this.renderOpponentBacks(p.id, count);
      });
    },

    notif_newHandCards: async function (args) {
      // Renderiza a nova mão recebida do servidor (apenas o próprio jogador)
      this.renderHand(args.hand || []);
    },

    notif_cardPlayed: async function (args) {
      console.log("notif_cardPlayed", args);
      if (args.player_id == this.player_id) {
        // Sou eu: remover carta da minha mão
        const node = this.playerHand[args.card.card_id];
        if (node) node.remove();
        delete this.playerHand[args.card.card_id];
      } else {
        // Adversário: remover um dorso da mão dele
        this.removeOneOpponentBack(args.player_id);
      }
      // Colocar na arena
      this.placeInArena(args.card, args.player_id);
    },

    notif_trickWon: async function (args) {
      console.log("notif_trickWon", args);
      // Mover cartas coletadas para a área do vencedor
      const area = document.getElementById(`area-${args.player_id}`);
      if (area && Array.isArray(args.cards)) {
        args.cards.forEach((card) => {
          area.appendChild(this.createCardNode(card));
        });
      }
      // Limpar arena visual
      const arena = document.getElementById("arena");
      if (arena) arena.innerHTML = "";
    },
  });
});
