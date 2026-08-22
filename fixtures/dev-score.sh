#!/usr/bin/env bash
#
# Add a goal to the ongoing dev fixture game so you can watch an overlay update
# while it is on air. Development helper only — it writes straight to the
# database and does not go through UltiOrganizer's scorekeeper.
#
#   live/overlays/fixtures/dev-score.sh home     # home team scores
#   live/overlays/fixtures/dev-score.sh visitor  # visiting team scores
#   live/overlays/fixtures/dev-score.sh undo     # remove the most recent goal
#   live/overlays/fixtures/dev-score.sh show     # print the current score
#
# Run from the UltiOrganizer root.

set -euo pipefail

GAME=${GAME:-702}
COMPOSE="docs/dev/compose.yaml"
ROOT_PW=$(grep -E '^MYSQL_ROOT_PASSWORD=' docs/dev/.env | cut -d= -f2-)
DB=${MYSQL_DATABASE:-ultiorganizer}

if [[ ! -f "$COMPOSE" ]]; then
  echo "Run this from the UltiOrganizer root directory." >&2
  exit 1
fi

sql() {
  docker compose -f "$COMPOSE" exec -T db \
    mariadb -uroot -p"$ROOT_PW" "$DB" --batch --skip-column-names -e "$1"
}

# The API caches an ongoing game for ~30s. Dropping the cached payload makes the
# change visible on the next poll instead of up to half a minute later.
bust_cache() {
  rm -f live/data/*_games_*.json live/data/*_games_*.lock 2>/dev/null || true
}

show() {
  sql "SELECT CONCAT('game $GAME  ', homescore, ' - ', visitorscore,
        '   (', (SELECT COUNT(*) FROM uo_goal WHERE game=$GAME), ' goals)')
       FROM uo_game WHERE game_id=$GAME;"
}

case "${1:-show}" in
  home|visitor)
    if [[ $1 == home ]]; then IS_HOME=1; else IS_HOME=0; fi
    sql "
      SET @num  = (SELECT COALESCE(MAX(num), 0) + 1 FROM uo_goal WHERE game=$GAME);
      SET @time = (SELECT COALESCE(MAX(time), 0) + 160 FROM uo_goal WHERE game=$GAME);
      SET @home = (SELECT homescore + $IS_HOME FROM uo_game WHERE game_id=$GAME);
      SET @away = (SELECT visitorscore + (1 - $IS_HOME) FROM uo_game WHERE game_id=$GAME);
      INSERT INTO uo_goal (game, num, assist, scorer, time, homescore, visitorscore,
                           ishomegoal, iscallahan, timestamp)
      VALUES ($GAME, @num, NULL, NULL, @time, @home, @away, $IS_HOME, 0, NOW());
      UPDATE uo_game SET homescore=@home, visitorscore=@away WHERE game_id=$GAME;
    "
    bust_cache
    show
    ;;
  undo)
    sql "
      SET @num = (SELECT MAX(num) FROM uo_goal WHERE game=$GAME);
      DELETE FROM uo_goal WHERE game=$GAME AND num=@num;
      UPDATE uo_game g SET
        homescore    = (SELECT COALESCE(MAX(homescore), 0)    FROM uo_goal WHERE game=$GAME),
        visitorscore = (SELECT COALESCE(MAX(visitorscore), 0) FROM uo_goal WHERE game=$GAME)
      WHERE g.game_id=$GAME;
    "
    bust_cache
    show
    ;;
  show)
    show
    ;;
  *)
    echo "Usage: $0 {home|visitor|undo|show}" >&2
    exit 1
    ;;
esac
