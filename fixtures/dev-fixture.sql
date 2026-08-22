-- Overlay development fixture: an in-progress game.
--
-- The upstream test-harness baseline (ktolonen/ultiorganizer-tests
-- fixtures/baseline.sql) only contains a finished game (700) and a scheduled
-- one (701). Overlays are built for games that are still being played, so this
-- adds a third game that is actually ongoing, with a goal sequence long enough
-- to exercise hold/break classification and score-change animation.
--
-- Load it after baseline.sql:
--   docker compose -f docs/dev/compose.yaml exec -T db \
--     mariadb -uroot -p<root-password> ultiorganizer < live/overlays/fixtures/dev-fixture.sql
--
-- Re-running it is safe: the rows are deleted first.

-- Realistic team names, from WFDF WUCC 2026 Mixed. The upstream baseline uses
-- short invented names ("Helsinki Heat"), which makes a scoreboard look fine
-- right up until a real tournament. These are near the long end of the real
-- range, so the layout is tested against the case that actually breaks it.
-- Restore the baseline names by re-running fixtures/baseline.sql.
UPDATE uo_team SET name = 'Mosquitos Klosterneuburg', abbreviation = 'MOS' WHERE team_id = 300;
UPDATE uo_team SET name = 'Leamington Lemmings',      abbreviation = 'LEM' WHERE team_id = 301;

DELETE FROM uo_goal WHERE game = 702;
DELETE FROM uo_gameevent WHERE game = 702;
DELETE FROM uo_played WHERE game = 702;
DELETE FROM uo_game_pool WHERE game = 702;
DELETE FROM uo_game WHERE game_id = 702;
DELETE FROM uo_reservation WHERE id = 502;
DELETE FROM uo_scheduling_name WHERE scheduling_id = 602;

INSERT INTO uo_reservation (
  id, location, fieldname, reservationgroup, starttime, endtime, season, timeslots, date
) VALUES
  (502, 400, '1', 'Harness Invitational 2026', '2026-06-02 10:00:00', '2026-06-02 11:30:00', 'HRN2026', NULL, '2026-06-02 00:00:00');

INSERT INTO uo_scheduling_name (scheduling_id, name) VALUES
  (602, 'Semi-final');

-- isongoing = 1 and hasstarted = 1: this is the state a broadcast overlay
-- actually runs against, and the one that gives the API a short cache life.
INSERT INTO uo_game (
  game_id, hometeam, visitorteam, homescore, visitorscore, reservation, time, valid,
  halftime, official, respteam, resppers, isongoing, scheduling_name_home, scheduling_name_visitor,
  name, timeslot, homedefenses, visitordefenses, hasstarted, islive, liveurl, timer_start,
  timer_pause_start, timer_paused_duration
) VALUES
  (702, 300, 301, 8, 6, 502, '2026-06-02 10:00:00', 1, 35, NULL, 300, NULL, 1, NULL, NULL,
   602, NULL, 0, 0, 1, 1, NULL, NULL, NULL, 0);

INSERT INTO uo_game_pool (game, pool, timetable) VALUES
  (702, 200, 1);

INSERT INTO uo_played (player, game, num, accredited, acknowledged, captain) VALUES
  (800, 702, 8, 1, 1, 1),
  (801, 702, 12, 1, 1, 0),
  (802, 702, 7, 1, 1, 1),
  (803, 702, 14, 1, 1, 0);

-- 14 points, home leading 8-6. The sequence deliberately contains both clean
-- alternation (holds) and consecutive same-team scores (breaks) so the
-- classifier has something to separate. Point 1 is only classifiable because of
-- the type='offence' event inserted below.
INSERT INTO uo_goal (
  game, num, assist, scorer, time, homescore, visitorscore, ishomegoal, iscallahan, timestamp
) VALUES
  (702,  1, 801, 800,  95, 1, 0, 1, 0, '2026-06-02 10:01:35'),
  (702,  2, 803, 802, 240, 1, 1, 0, 0, '2026-06-02 10:04:00'),
  (702,  3, 800, 801, 400, 2, 1, 1, 0, '2026-06-02 10:06:40'),
  (702,  4, 801, 800, 560, 3, 1, 1, 0, '2026-06-02 10:09:20'),
  (702,  5, 802, 803, 720, 3, 2, 0, 0, '2026-06-02 10:12:00'),
  (702,  6, 803, 802, 880, 3, 3, 0, 0, '2026-06-02 10:14:40'),
  (702,  7, 801, 800,1040, 4, 3, 1, 0, '2026-06-02 10:17:20'),
  (702,  8, 800, 801,1200, 5, 3, 1, 0, '2026-06-02 10:20:00'),
  (702,  9, 803, 802,1360, 5, 4, 0, 0, '2026-06-02 10:22:40'),
  (702, 10, 801, 800,1520, 6, 4, 1, 0, '2026-06-02 10:25:20'),
  (702, 11, 802, 803,1680, 6, 5, 0, 0, '2026-06-02 10:28:00'),
  (702, 12, 800, 801,1840, 7, 5, 1, 0, '2026-06-02 10:30:40'),
  (702, 13, 803, 802,2000, 7, 6, 0, 0, '2026-06-02 10:33:20'),
  (702, 14, 801, 800,2160, 8, 6, 1, 0, '2026-06-02 10:36:00');

-- Halftime cap after point 8, plus one timeout per side.
--
-- The num=0 type='offence' row is how UltiOrganizer records which team started
-- on offence — written by Scorekeeper's "First offence" page via
-- GameSetStartingTeam(), read back by GameIsFirstOffenceHome(). ishome=1 means
-- the home team received the opening pull.
--
-- It is optional in real data, and it is the only thing that makes the first
-- point of a game classifiable as a hold or a break. Drop this row and
-- classifyPoints() reports one unresolved point instead of guessing.
INSERT INTO uo_gameevent (game, num, time, type, ishome, info) VALUES
  (702, 0,    0, 'offence',   1, NULL),
  (702, 1, 1260, 'half_cap',  0, '8'),
  (702, 2,  600, 'timeout',   1, NULL),
  (702, 3, 1700, 'timeout',   0, NULL);


-- ---------------------------------------------------------------------------
-- Full rosters: 28 players a side.
--
-- The original two-per-team fixture was enough to prove a pipeline but not to
-- exercise anything that renders a squad — a roster list, its scrolling, or a
-- sort. 28 is the largest squad seen at a tournament, so it is the size the
-- commentator page has to survive rather than a comfortable one.
--
-- Jersey numbers are scattered rather than 1..28, because real squads have gaps
-- and a list sorted by number should look like one.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO uo_player (player_id, firstname, lastname, team, num) VALUES
(810,'Alex','Auer',300,1),
(811,'Robin','Brand',300,3),
(812,'Sam','Cerny',300,4),
(813,'Jules','Draxler',300,5),
(814,'Kim','Ebner',300,7),
(815,'Noa','Fuchs',300,9),
(816,'Toni','Gruber',300,10),
(817,'Mika','Huber',300,13),
(818,'Lou','Illig',300,14),
(819,'Vic','Jandl',300,15),
(820,'Rene','Kral',300,17),
(821,'Sacha','Lang',300,18),
(822,'Andrea','Moser',300,19),
(823,'Charlie','Novak',300,21),
(824,'Devon','Ortner',300,22),
(825,'Emery','Pichler',300,23),
(826,'Frankie','Reiter',300,25),
(827,'Gale','Steiner',300,26),
(828,'Harper','Traxler',300,27),
(829,'Indigo','Urban',300,29),
(830,'Jesse','Vogel',300,30),
(831,'Kai','Wagner',300,31),
(832,'Lior','Zeman',300,33),
(833,'Marley','Bauer',300,34),
(834,'Nico','Dorner',300,35),
(835,'Quinn','Egger',300,37),
(840,'Alex','Ashby',301,1),
(841,'Robin','Bennett',301,3),
(842,'Sam','Clarke',301,4),
(843,'Jules','Dawson',301,5),
(844,'Kim','Ellis',301,8),
(845,'Noa','Foster',301,9),
(846,'Toni','Gibbs',301,10),
(847,'Mika','Hale',301,12),
(848,'Lou','Ingram',301,13),
(849,'Vic','Jarvis',301,15),
(850,'Rene','Keane',301,17),
(851,'Sacha','Lowry',301,18),
(852,'Andrea','Mercer',301,19),
(853,'Charlie','Nash',301,21),
(854,'Devon','Osborne',301,22),
(855,'Emery','Palmer',301,23),
(856,'Frankie','Quill',301,25),
(857,'Gale','Radley',301,26),
(858,'Harper','Sutton',301,27),
(859,'Indigo','Thorne',301,29),
(860,'Jesse','Vance',301,30),
(861,'Kai','Whitby',301,31),
(862,'Lior','Yates',301,33),
(863,'Marley','Abbott',301,34),
(864,'Nico','Bright',301,35),
(865,'Quinn','Chase',301,37);


-- ---------------------------------------------------------------------------
-- Defensive plays (blocks)
--
-- Two independent things have to be true before a block reaches a payload, and
-- both ship off: the installation setting ShowDefenseStats, and scorekeepers
-- actually recording defence. This section turns on the first and supplies the
-- second, so the commentator page's Blk column and Blocks sort have something
-- to show locally.
--
-- Note the shape of the data this produces, because it is the shape a real
-- tournament produces too: TeamScoreBoardWithDefenses() counts only games with
-- isongoing=0, so blocks made in the game currently on the clock (702) are not
-- in a player's tournament total until that game is closed.
-- ---------------------------------------------------------------------------

-- uo_setting has no unique key on `name`, so this is an update-then-insert
-- rather than an upsert: ON DUPLICATE KEY would never fire and would append a
-- second ShowDefenseStats row on every re-run.
UPDATE uo_setting SET value = 'true' WHERE name = 'ShowDefenseStats';

INSERT INTO uo_setting (name, value)
SELECT 'ShowDefenseStats', 'true'
WHERE NOT EXISTS (SELECT 1 FROM (SELECT name FROM uo_setting) AS existing
                  WHERE existing.name = 'ShowDefenseStats');

INSERT IGNORE INTO uo_defense (game, num, author, time, iscallahan, iscaught, ishomedefense) VALUES
-- game 700 (completed): these land in the tournament totals
(700,1,811,90,0,1,1),
(700,2,845,240,0,0,0),
(700,3,811,400,0,0,1),
(700,4,814,560,0,0,1),
(700,5,846,700,0,1,0),
(700,6,802,860,0,0,1),
(700,7,815,1010,0,0,1),
-- game 702 (ongoing): recorded, but deliberately not yet in any total
(702,1,810,120,0,1,1),
(702,2,811,310,0,0,1),
(702,3,810,455,0,1,1),
(702,4,802,620,0,0,1),
(702,5,845,780,0,0,0),
(702,6,812,910,0,0,1),
(702,7,800,1040,0,1,1),
(702,8,846,1180,0,0,0),
(702,9,810,1320,0,0,1),
(702,10,813,1495,0,0,1),
(702,11,847,1610,0,0,0),
(702,12,802,1740,0,1,1);
