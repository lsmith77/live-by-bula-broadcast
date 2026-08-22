<?php
/**
 * Pre-game team statistics — NOT YET PORTED TO LIVE! v3.
 *
 * The markup and layout below are kept as the starting point for the Phase 2
 * port (see ../docs/PLAN.md). Its former data source, shared/pregame-stats.php, was
 * deleted: every query it made was against tables and columns that do not exist
 * in UltiOrganizer 4, and it called DBFetchRow(), which UO 4 does not define.
 *
 * The port replaces it with the routed API — entity=teams for the win/loss
 * record, and classifyPoints() in shared/overlay-client.js for holds and breaks
 * derived from entity=games goals.
 */

http_response_code(501);
header('Content-Type: text/plain; charset=UTF-8');
echo "Pre-game statistics overlay is not ported to Live! v3 yet. See live/overlays/docs/PLAN.md, Phase 2.";
return;

// ---------------------------------------------------------------------------
// Phase 2 starting point below this line.
// ---------------------------------------------------------------------------

// Get game ID from URL parameter
$gameId = isset($_GET['game']) ? (int)$_GET['game'] : null;

if (!$gameId) {
    die('Error: No game ID specified. Add ?game=123 to the URL.');
}

// Get game info to find teams and season
$gameInfo = GameResult($gameId);
if (!$gameInfo) {
    die('Error: Game not found.');
}

$homeTeamId = $gameInfo['hometeam'];
$awayTeamId = $gameInfo['visitorteam'];
$poolId = $gameInfo['pool'];

// Get pool and series info
$poolInfo = PoolInfo($poolId);
$seriesId = $poolInfo['series'];

// Get team names
$homeTeam = TeamInfo($homeTeamId);
$awayTeam = TeamInfo($awayTeamId);

// Get historical stats from current tournament only
$homeStats = getTeamHistoricalStats($homeTeamId, $seriesId, $gameId);
$awayStats = getTeamHistoricalStats($awayTeamId, $seriesId, $gameId);

// Get spirit scores from current tournament only
$homeSpirit = getTeamSpiritScores($homeTeamId, $seriesId);
$awaySpirit = getTeamSpiritScores($awayTeamId, $seriesId);

// Get optional parameters
$position = isset($_GET['position']) ? htmlspecialchars($_GET['position']) : 'center';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UltiOrganizer Overlay - Pre-Game Stats - Game <?php echo $gameId; ?></title>
    
    <!-- Base overlay styles -->
    <link rel="stylesheet" href="../shared/overlay-base.css">
    
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        
        .pregame-container {
            background: rgba(0, 0, 0, 0.9);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
            max-width: 1000px;
        }
        
        .pregame-header {
            text-align: center;
            margin-bottom: 32px;
            padding-bottom: 24px;
            border-bottom: 2px solid rgba(255, 255, 255, 0.1);
        }
        
        .pregame-title {
            font-size: 28px;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 8px;
        }
        
        .pregame-subtitle {
            font-size: 16px;
            color: rgba(255, 255, 255, 0.7);
        }
        
        .matchup {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 32px;
            margin-bottom: 40px;
            font-size: 32px;
            font-weight: 800;
            color: #ffffff;
        }
        
        .team-name-large {
            flex: 1;
            text-align: center;
        }
        
        .vs {
            color: rgba(255, 255, 255, 0.5);
            font-size: 24px;
            font-weight: 600;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: 32px;
            margin-bottom: 32px;
        }
        
        .stat-section {
            margin-bottom: 32px;
        }
        
        .stat-section-title {
            font-size: 18px;
            font-weight: 700;
            color: #4ade80;
            margin-bottom: 16px;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .stat-row {
            display: contents;
        }
        
        .stat-label {
            text-align: center;
            padding: 12px 16px;
            color: rgba(255, 255, 255, 0.7);
            font-size: 14px;
            font-weight: 600;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .stat-value {
            padding: 12px 16px;
            color: #ffffff;
            font-size: 18px;
            font-weight: 700;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .stat-value.home {
            text-align: right;
        }
        
        .stat-value.away {
            text-align: left;
        }
        
        .stat-value.better {
            color: #4ade80;
        }
        
        .stat-value.worse {
            color: #f87171;
        }
        
        .no-data {
            grid-column: 1 / -1;
            text-align: center;
            padding: 20px;
            color: rgba(255, 255, 255, 0.5);
            font-style: italic;
        }
        
        .spirit-bars {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .spirit-bar {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .spirit-bar-label {
            width: 120px;
            font-size: 13px;
            color: rgba(255, 255, 255, 0.7);
            text-align: right;
        }
        
        .spirit-bar-track {
            flex: 1;
            height: 8px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            position: relative;
            overflow: hidden;
        }
        
        .spirit-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #4ade80, #22c55e);
            border-radius: 4px;
            transition: width 0.5s ease;
        }
        
        .spirit-bar-value {
            width: 40px;
            text-align: left;
            font-size: 13px;
            font-weight: 700;
            color: #ffffff;
        }
        
        .record {
            font-size: 20px;
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 4px;
        }
    </style>
</head>
<body>
    <div class="pregame-container">
        <div class="pregame-header">
            <div class="pregame-title">Pre-Game Team Statistics</div>
            <div class="pregame-subtitle"><?php echo htmlspecialchars($poolInfo['name'] ?? 'Game'); ?></div>
        </div>
        
        <div class="matchup">
            <div class="team-name-large"><?php echo htmlspecialchars($homeTeam['name']); ?></div>
            <div class="vs">VS</div>
            <div class="team-name-large"><?php echo htmlspecialchars($awayTeam['name']); ?></div>
        </div>
        
        <!-- Tournament Record -->
        <div class="stat-section">
            <div class="stat-section-title">Tournament Record</div>
            <div class="stats-grid">
                <div class="stat-value home">
                    <div class="record"><?php echo $homeStats['wins']; ?>-<?php echo $homeStats['losses']; ?></div>
                    <?php if ($homeStats['games_played'] > 0): ?>
                        <div style="font-size: 14px; color: rgba(255,255,255,0.6);">
                            <?php echo round($homeStats['win_pct']); ?>% win rate
                        </div>
                    <?php endif; ?>
                </div>
                <div class="stat-label">Record</div>
                <div class="stat-value away">
                    <div class="record"><?php echo $awayStats['wins']; ?>-<?php echo $awayStats['losses']; ?></div>
                    <?php if ($awayStats['games_played'] > 0): ?>
                        <div style="font-size: 14px; color: rgba(255,255,255,0.6);">
                            <?php echo round($awayStats['win_pct']); ?>% win rate
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Scoring Stats -->
        <?php if ($homeStats['games_played'] > 0 && $awayStats['games_played'] > 0): ?>
        <div class="stat-section">
            <div class="stat-section-title">Scoring Averages</div>
            <div class="stats-grid">
                <div class="stat-row">
                    <div class="stat-value home <?php echo $homeStats['avg_score_for'] > $awayStats['avg_score_for'] ? 'better' : ''; ?>">
                        <?php echo number_format($homeStats['avg_score_for'], 1); ?>
                    </div>
                    <div class="stat-label">Avg Goals For</div>
                    <div class="stat-value away <?php echo $awayStats['avg_score_for'] > $homeStats['avg_score_for'] ? 'better' : ''; ?>">
                        <?php echo number_format($awayStats['avg_score_for'], 1); ?>
                    </div>
                </div>
                
                <div class="stat-row">
                    <div class="stat-value home <?php echo $homeStats['avg_score_against'] < $awayStats['avg_score_against'] ? 'better' : ''; ?>">
                        <?php echo number_format($homeStats['avg_score_against'], 1); ?>
                    </div>
                    <div class="stat-label">Avg Goals Against</div>
                    <div class="stat-value away <?php echo $awayStats['avg_score_against'] < $homeStats['avg_score_against'] ? 'better' : ''; ?>">
                        <?php echo number_format($awayStats['avg_score_against'], 1); ?>
                    </div>
                </div>
                
                <div class="stat-row">
                    <div class="stat-value home <?php echo $homeStats['avg_margin'] > $awayStats['avg_margin'] ? 'better' : ($homeStats['avg_margin'] < $awayStats['avg_margin'] ? 'worse' : ''); ?>">
                        <?php echo $homeStats['avg_margin'] > 0 ? '+' : ''; ?><?php echo number_format($homeStats['avg_margin'], 1); ?>
                    </div>
                    <div class="stat-label">Avg Margin of Victory</div>
                    <div class="stat-value away <?php echo $awayStats['avg_margin'] > $homeStats['avg_margin'] ? 'better' : ($awayStats['avg_margin'] < $homeStats['avg_margin'] ? 'worse' : ''); ?>">
                        <?php echo $awayStats['avg_margin'] > 0 ? '+' : ''; ?><?php echo number_format($awayStats['avg_margin'], 1); ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Offensive Efficiency -->
        <div class="stat-section">
            <div class="stat-section-title">Offensive Efficiency</div>
            <div class="stats-grid">
                <div class="stat-row">
                    <div class="stat-value home <?php echo $homeStats['hold_pct'] > $awayStats['hold_pct'] ? 'better' : ''; ?>">
                        <?php echo number_format($homeStats['hold_pct'], 1); ?>%
                    </div>
                    <div class="stat-label">Hold Percentage</div>
                    <div class="stat-value away <?php echo $awayStats['hold_pct'] > $homeStats['hold_pct'] ? 'better' : ''; ?>">
                        <?php echo number_format($awayStats['hold_pct'], 1); ?>%
                    </div>
                </div>
                
                <div class="stat-row">
                    <div class="stat-value home <?php echo $homeStats['break_pct'] > $awayStats['break_pct'] ? 'better' : ''; ?>">
                        <?php echo number_format($homeStats['break_pct'], 1); ?>%
                    </div>
                    <div class="stat-label">Break Percentage</div>
                    <div class="stat-value away <?php echo $awayStats['break_pct'] > $homeStats['break_pct'] ? 'better' : ''; ?>">
                        <?php echo number_format($awayStats['break_pct'], 1); ?>%
                    </div>
                </div>
                
                <div class="stat-row">
                    <div class="stat-value home">
                        <?php echo $homeStats['breaks_converted']; ?>/<?php echo $homeStats['break_opportunities']; ?>
                    </div>
                    <div class="stat-label">Breaks Converted</div>
                    <div class="stat-value away">
                        <?php echo $awayStats['breaks_converted']; ?>/<?php echo $awayStats['break_opportunities']; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Spirit Scores -->
        <?php if ($homeSpirit || $awaySpirit): ?>
        <div class="stat-section">
            <div class="stat-section-title">Spirit of the Game (Average Scores)</div>
            
            <?php if ($homeSpirit && $awaySpirit): ?>
            <div class="stats-grid" style="margin-bottom: 24px;">
                <div class="stat-value home <?php echo $homeSpirit['total'] > $awaySpirit['total'] ? 'better' : ''; ?>">
                    <?php echo $homeSpirit['total']; ?>/20
                </div>
                <div class="stat-label">Total Spirit Score</div>
                <div class="stat-value away <?php echo $awaySpirit['total'] > $homeSpirit['total'] ? 'better' : ''; ?>">
                    <?php echo $awaySpirit['total']; ?>/20
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 40px;">
                <div>
                    <h4 style="color: #fff; text-align: center; margin-bottom: 16px; font-size: 16px;">
                        <?php echo htmlspecialchars($homeTeam['name']); ?>
                    </h4>
                    <div class="spirit-bars">
                        <div class="spirit-bar">
                            <div class="spirit-bar-label">Rules Knowledge</div>
                            <div class="spirit-bar-track">
                                <div class="spirit-bar-fill" style="width: <?php echo ($homeSpirit['rules']/4)*100; ?>%;"></div>
                            </div>
                            <div class="spirit-bar-value"><?php echo $homeSpirit['rules']; ?></div>
                        </div>
                        <div class="spirit-bar">
                            <div class="spirit-bar-label">Fouls & Body Contact</div>
                            <div class="spirit-bar-track">
                                <div class="spirit-bar-fill" style="width: <?php echo ($homeSpirit['fouls']/4)*100; ?>%;"></div>
                            </div>
                            <div class="spirit-bar-value"><?php echo $homeSpirit['fouls']; ?></div>
                        </div>
                        <div class="spirit-bar">
                            <div class="spirit-bar-label">Fair-Mindedness</div>
                            <div class="spirit-bar-track">
                                <div class="spirit-bar-fill" style="width: <?php echo ($homeSpirit['fair']/4)*100; ?>%;"></div>
                            </div>
                            <div class="spirit-bar-value"><?php echo $homeSpirit['fair']; ?></div>
                        </div>
                        <div class="spirit-bar">
                            <div class="spirit-bar-label">Positive Attitude</div>
                            <div class="spirit-bar-track">
                                <div class="spirit-bar-fill" style="width: <?php echo ($homeSpirit['positive']/4)*100; ?>%;"></div>
                            </div>
                            <div class="spirit-bar-value"><?php echo $homeSpirit['positive']; ?></div>
                        </div>
                        <div class="spirit-bar">
                            <div class="spirit-bar-label">Communication</div>
                            <div class="spirit-bar-track">
                                <div class="spirit-bar-fill" style="width: <?php echo ($homeSpirit['communication']/4)*100; ?>%;"></div>
                            </div>
                            <div class="spirit-bar-value"><?php echo $homeSpirit['communication']; ?></div>
                        </div>
                    </div>
                </div>
                
                <div>
                    <h4 style="color: #fff; text-align: center; margin-bottom: 16px; font-size: 16px;">
                        <?php echo htmlspecialchars($awayTeam['name']); ?>
                    </h4>
                    <div class="spirit-bars">
                        <div class="spirit-bar">
                            <div class="spirit-bar-label">Rules Knowledge</div>
                            <div class="spirit-bar-track">
                                <div class="spirit-bar-fill" style="width: <?php echo ($awaySpirit['rules']/4)*100; ?>%;"></div>
                            </div>
                            <div class="spirit-bar-value"><?php echo $awaySpirit['rules']; ?></div>
                        </div>
                        <div class="spirit-bar">
                            <div class="spirit-bar-label">Fouls & Body Contact</div>
                            <div class="spirit-bar-track">
                                <div class="spirit-bar-fill" style="width: <?php echo ($awaySpirit['fouls']/4)*100; ?>%;"></div>
                            </div>
                            <div class="spirit-bar-value"><?php echo $awaySpirit['fouls']; ?></div>
                        </div>
                        <div class="spirit-bar">
                            <div class="spirit-bar-label">Fair-Mindedness</div>
                            <div class="spirit-bar-track">
                                <div class="spirit-bar-fill" style="width: <?php echo ($awaySpirit['fair']/4)*100; ?>%;"></div>
                            </div>
                            <div class="spirit-bar-value"><?php echo $awaySpirit['fair']; ?></div>
                        </div>
                        <div class="spirit-bar">
                            <div class="spirit-bar-label">Positive Attitude</div>
                            <div class="spirit-bar-track">
                                <div class="spirit-bar-fill" style="width: <?php echo ($awaySpirit['positive']/4)*100; ?>%;"></div>
                            </div>
                            <div class="spirit-bar-value"><?php echo $awaySpirit['positive']; ?></div>
                        </div>
                        <div class="spirit-bar">
                            <div class="spirit-bar-label">Communication</div>
                            <div class="spirit-bar-track">
                                <div class="spirit-bar-fill" style="width: <?php echo ($awaySpirit['communication']/4)*100; ?>%;"></div>
                            </div>
                            <div class="spirit-bar-value"><?php echo $awaySpirit['communication']; ?></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div style="text-align: center; margin-top: 16px; font-size: 13px; color: rgba(255,255,255,0.5);">
                Based on <?php echo $homeSpirit['count']; ?> and <?php echo $awaySpirit['count']; ?> spirit evaluations
            </div>
            <?php else: ?>
            <div class="no-data">Spirit scores not available for one or both teams</div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($homeStats['games_played'] == 0 && $awayStats['games_played'] == 0): ?>
        <div class="no-data" style="margin-top: 40px;">
            No previous games found for these teams in this tournament.<br>
            <span style="font-size: 12px; margin-top: 8px; display: block;">This appears to be their first game.</span>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
