-- ------
-- BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
-- Gladiadores implementation : © <Your name here> <Your email address here>
-- 
-- This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
-- See http://en.boardgamearena.com/#!doc/Studio for more information.
-- -----
-- dbmodel.sql
-- This is the file where you are describing the database schema of your game
-- Basically, you just have to export from PhpMyAdmin your table structure and copy/paste
-- this export here.
-- Note that the database itself and the standard tables ("global", "stats", "gamelog" and "player") are
-- already created and must not be created here
-- Note: The database schema is created from this file when the game starts. If you modify this file,
--       you have to restart a game to see your changes in database.
-- Example 1: create a standard "card" table to be used with the "Deck" tools (see example game "hearts"):
-- CREATE TABLE IF NOT EXISTS `card` (
--   `card_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
--   `card_type` varchar(16) NOT NULL,
--   `card_type_arg` int(11) NOT NULL,
--   `card_location` varchar(16) NOT NULL,
--   `card_location_arg` int(11) NOT NULL,
--   PRIMARY KEY (`card_id`)
-- ) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;
-- Example 2: add a custom field to the standard "player" table
-- ALTER TABLE `player` ADD `player_my_custom_field` INT UNSIGNED NOT NULL DEFAULT '0';
--
-- Estrutura de banco de dados para o jogo Gladiadores
--

--
-- Tabela de cartas
--
CREATE TABLE IF NOT EXISTS `card` (
`card_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
`type` ENUM('combat', 'lion', 'damaged') NOT NULL,
`suit` CHAR(1) DEFAULT NULL,
-- 'T','M','G','X' ou 'N'
`value` INT NOT NULL DEFAULT 0,
-- 1..10 combate, 11 leão, 0 damaged
`dual_suits` VARCHAR(5) DEFAULT NULL,
-- ex: 'T|M' para arma danificada
`location` VARCHAR(50) NOT NULL,
-- draw, hand_<id>, arena_<id>, area_<id>, discard, aside
`location_arg` INT NOT NULL DEFAULT 0,
-- ordem no local
PRIMARY KEY (`card_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8;
--
-- Índices para performance
--
CREATE INDEX `idx_card_location` ON `card` (`location`);
CREATE INDEX `idx_card_location_arg` ON `card` (`location_arg`);

--
-- Trilhas de glória (lado A): posição de cada jogador em cada naipe
--
CREATE TABLE IF NOT EXISTS `glory_track` (
  `player_id` INT UNSIGNED NOT NULL,
  `suit` CHAR(1) NOT NULL,
  `position` INT NOT NULL DEFAULT 7,
  PRIMARY KEY (`player_id`, `suit`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8;