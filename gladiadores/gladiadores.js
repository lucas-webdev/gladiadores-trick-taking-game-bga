define([
  "dojo",
  "dojo/_base/declare",
  "ebg/core/gamegui",
  "ebg/counter",
], function (dojo, declare) {
  return declare("bgagame.gladiadores", ebg.core.gamegui, {
    constructor: function () {
      console.log("gladiadores constructor");
      this.playerHand = {}; // card_id -> DOM
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

      // criar zonas por jogador (inclui minha mão ANTES de renderizar)
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
      if (!container) return; // evita crash

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
      // Se vier assetCode do PHP, aplica sprite; senão mostra texto
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
      slot.appendChild(this.createCardNode(card));
      document.getElementById("arena").appendChild(slot);
    },

    /* ---------- estados ---------- */

    onEnteringState: function (stateName, args) {
      console.log("Entering state:", stateName, args);
      // Nenhum painel flutuante por enquanto
    },

    onLeavingState: function (stateName) {
      console.log("Leaving state:", stateName);
    },

    onUpdateActionButtons: function (stateName, args) {
      console.log("onUpdateActionButtons:", stateName, args);
      // Estados válidos: 'trickLead' e 'trickFollow'. Sem "Passar".
      if (!this.isCurrentPlayerActive()) return;

      switch (stateName) {
        case "trickLead":
        case "trickFollow":
          // A UI usa clique nas cartas. Sem botões extras.
          break;
      }
    },

    /* ---------- ações ---------- */

    onCardClick: function (card) {
      if (!this.isCurrentPlayerActive()) return;

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
      // dual_suits pode vir como "T|M"; se não vier, pedir genericamente
      return new Promise((resolve) => {
        const suitsFromCard =
          card.dual_suits && typeof card.dual_suits === "string"
            ? card.dual_suits.split("|")
            : ["T", "M", "G", "X"];

        // caixa simples usando browser prompt para começar
        const label =
          "Escolha o naipe para a arma danificada: " + suitsFromCard.join("/");
        const ans = window.prompt(label, suitsFromCard[0] || "T");
        const pick = (ans || "").toUpperCase();
        if (suitsFromCard.includes(pick)) resolve(pick);
        else resolve(null);
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
      // Limpa arena visual
      const arena = document.getElementById("arena");
      if (arena) arena.innerHTML = "";
      // Mão será recarregada quando servidor enviar getAllDatas no refresh automático
    },

    notif_cardPlayed: async function (args) {
      console.log("notif_cardPlayed", args);
      // Se sou eu, remover da mão
      if (args.player_id == this.player_id) {
        const node = this.playerHand[args.card.card_id];
        if (node) node.remove();
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
