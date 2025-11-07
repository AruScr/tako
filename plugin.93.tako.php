<?php
/////////////////////////////////////////////////////////////////////////////////////////////////////////////////
//
// Plugin: tako (Time Attack Knock-Out)
// For: FAST 3.2 (First Automatic Server for Trackmania) by Gilles Masson
// Author: CMC_Aru
// Version: 1.0
// Date: Nov 7 2025
// Description: Time Attack knockout mode — slowest players are eliminated each map until one winner remains.
//
/////////////////////////////////////////////////////////////////////////////////////////////////////////////////

/*
 * Installation:
 * 
 * Place plugin.93.tako.php in the fast installation /plugins folder
 * Restart FAST.
 * 
 * Instructions:
 * 
 * tako does not touch the gamemode/matchsettings/maps, so...
 * ...You have to load your own matchsettings. Use Time Attack mode. TA KO feels best with short maps and a short TA Timelimit.
 * I recommend loading matchsettings > configure tako elims > /adm next > in podium screen or at the start of the map /tako on.
 * 
 * FAST Admins can use chat commands /tako on, off, pause, elim [num]|[preset]|custom
 * - /tako on: Starts a tako match. Enter this command at the start of your first map.
 * - /tako off: Stops a tako match. Warning, doesn't save tako's state! Only use if you want to force tako to stop. After a winner is chosen, tako automatically turns off.
 * - /tako pause: Toggles pause to stop eliminations, needed if you want to restart/next the map without eliminating players.
 * - /tako elim [num]|[preset]|custom: Sets eliminations per map, or applies a threshold format that automatically adjust eliminations per map based on how many players are still alive.
 *      - [num]: Enter a number for eliminations per map. Min 1, max 7. Example: /tako elim 4
 *      - [preset]: Enter the name of a threshold preset. Included presets are smooth|ktlc|cotd (find them in takoInit). Example: /tako elim smooth
 *      - custom: Enter your own threshold values in this format: [alivePlayers]=[elimAmount] separated by a space. Example: /tako elim custom 255=6 64=4 16=2 8=1
 * - Available aliases for commands: on|start, off|stop, pause|p, elim|e
 * 
 * tako eliminates players at the end of a race, right before podium screen. If tako is paused at that moment, it will not eliminate anyone.
 * At elimination, all players with no time will be eliminated, this is allowed to exceed the current eliminations per map.
 * Ties between players are resolved like this: The player who set the time first is ranked higher. If the timestamp between both time is somehow the same (not sure if possible), login alphabetically is used.
 * 
 * Eliminated players can still drive and set times if they like, their times just won't count. There is no automatic force speccing.
 * 
 * Spectators can click on the players displayed in the tako hud to switch their spec target to them.
 * 
 * After every map, current standings are logged.
 * After a tako match concludes, final results are logged.
 * Log can be found in fastlog/tako.log.game.servername.txt
 * Log format: currentRank,finalRank,login,status,eliminatedRound,bestTime(formatted),bestTime(ms),nickname(text),nickname(colored)
 * 
 * After a tako match concludes, an html file is generated in fastlog/htmlResults/tako_game.servername.datetime.html.
 * This shows a table of the results that can be easily screenshotted.
 * You can turn it off in takoInit() > $tako_config > generateHtmlResult => false
 */



registerPlugin('tako', 93);



// ----------------------
// FAST CALLBACKS
// ----------------------
function takoInit() {

    global $tako_state, $tako_config, $tako_elimThresholdsPresets, $tako_commandAliases, $tako_specActions, $_ml_act;

    // Config
    $tako_config = array(
        'elimCount' => 2, // default amount of slowest players eliminated each map
        'elimPaused' => false, // holds toggle for pausing eliminations, default false
        'elimThresholds' => array(), // holds current elimination thresholds, this should really be in $tako_state
        'minElims' => 1, // don't change
        'maxElims' => 7, // don't change, limited to 7 by display having 10 player slots (red: 7 + yellow: 1 + top1: 1 + the player: 1)
        'maxDisplaySlots' => 10, // don't change, amount of player slots the xml shows
        'xmlUpdateInterval' => 0.15, // seconds, limits times per second xml updates get sent. FAST generally runs at ~0.2s tickrate (5hz).
        'generateHtmlResult' => true, // turn on/off the automatically generated html file.
    );

    // State
    $tako_state = array(
        'running'    => false,
        'players'    => array(), // See tako_addPlayer for format
        'nextThreshold' => -1,
        'mapNumber'  => 0,
        'entryOpen' => true,
        'xmlNeedsUpdate' => false,
        'xmlLastUpdate' => 0,
        'xmlSpec' => array(), // for each login, holds all logins visible in their current visible xml panel. For clicking to spec.
    );

    // Threshold Presets, you can add more here. 255 represents the maximum players on the server.
    // If there are more players than the highest number in the array, tako uses maxElims instead.
    $tako_elimThresholdsPresets = array(
        'smooth' => array( // Every ~2 maps decrease by 1 with 2 as the minimum
            '255' => 7,
            '40' => 6,
            '28' => 5,
            '18' => 4,
            '10' => 3,
            '4' => 2,
        ),
        'cotd' => array( // Cup of the day format
            '255' => 6,
            '64' => 4,
            '16' => 2,
            '8' => 1,
        ),
        'ktlc' => array( // KTLC format, extrapolated to every ~5 maps decrease by 1
            '255' => 6,
            '78' => 5,
            '53' => 4,
            '33' => 3,
            '18' => 2,
            '8' => 1,
            '3' => 2,
        ),
    );

    $tako_commandAliases = array(
        'e'     => 'elim',
        'p'     => 'pause',
        'start' => 'on',
        'stop'  => 'off'
    );

    // Manialink setup
    manialinksAddId('tako.bg');
    manialinksAddId('tako.header');
    manialinksAddId('tako.rank');
    manialinksAddId('tako.0');
    manialinksAddId('tako.1');
    manialinksAddId('tako.2');
    manialinksAddId('tako.3');
    manialinksAddId('tako.4');
    manialinksAddId('tako.5');
    manialinksAddId('tako.6');
    manialinksAddId('tako.7');
    manialinksAddId('tako.8');
    manialinksAddId('tako.9');

    $tako_specActions = array();
    for ($i = 0; $i < $tako_config['maxDisplaySlots']; $i++){
        $actionString = 'tako.spec.' . strval($i);
        manialinksAddAction($actionString);
        $tako_specActions[$i] = $_ml_act[$actionString];
    }

    // Help command
    registerCommand('tako','/tako on, off, pause, elim [num|preset|custom]', true);
}


function takoPlayerConnect($event,$login){
    global $tako_state;

	if (!$tako_state['running']) return;

    tako_addPlayer($login);
	tako_updateXml($login,'show');
}


function takoPlayerShowML($event,$login,$ShowML){
    global $tako_state;

    if (!$tako_state['running']) return;

	if($ShowML>0){
		tako_updateXml($login,'show');
    }
}


function takoPlayerManialinkPageAnswer($event,$login,$answer,$action){
	global $tako_state, $tako_specActions, $_players;

    if (!$tako_state['running']) return;

    if (!isset($_players[$login])){
        console('TAKO warning: supposed spec is not in $_players');
        return;
    }
    if (!$_players[$login]['IsSpectator'] && !$_players[$login]['IsTemporarySpectator']){
        console('TAKO warning: supposed spec is not spectating');
        return;
    }

    for ($i = 0; $i < count($tako_specActions); $i++){
        $actionString = 'tako.spec.' . strval($i);        
        if ($action !== $actionString){
            continue;
        }

        $specLogin = $tako_state['xmlSpec'][$login][$i];

        if ($specLogin === $login){ // can't spec self
            break;
        }

        if (!isset($_players[$specLogin])){
            console('TAKO warning: specLogin is not in $_players');
            break;
        }
        if (!$_players[$specLogin]['Active']){
            console('TAKO warning: specLogin is not Active');
            break;
        }
        if ($_players[$specLogin]['IsSpectator'] || $_players[$specLogin]['IsTemporarySpectator']){
            console('TAKO warning: specLogin is not playing');
            break;
        }

        addCall(true, 'ForceSpectatorTarget', $login, $specLogin, -1);

        break;
    }
}


function takoBeginRace($event) {
    global $tako_state;

    if (!$tako_state['running']) return;

    // Reset per-map times
    foreach ($tako_state['players'] as $login => &$p) {
        $p['bestTime'] = null;
        $p['bestTimeTimestamp'] = null;
    }
    unset($p); // break reference

    tako_updateElimCountByThreshold();

    $tako_state['xmlNeedsUpdate'] = true;

    //tako_sendChat("TA KO: Starting map " . $tako_state['mapNumber']);
}


function takoPlayerFinish($event, $Login, $time) {
    global $tako_state;

	if (!$tako_state['running'] || $time <= 0) return;

	// Add new player if not yet tracked
    tako_addPlayer($Login);

	// Add or update best time
	if ($tako_state['players'][$Login]['bestTime'] === null || $time < $tako_state['players'][$Login]['bestTime']) {
        $tako_state['players'][$Login]['bestTime'] = $time;
        $tako_state['players'][$Login]['bestTimeTimestamp'] = microtime(true);
	}

    // Skip sorting for non-alive players
    if ($tako_state['players'][$Login]['status'] !== 'alive') return;

    tako_sortPlayers($tako_state['players']);
}


function takoEndRace($event, $Ranking, $ChallengeInfo, $GameInfos) {
    global $tako_state;

    if (!$tako_state['running']) return;

    tako_processEliminations();
}


function takoPlayerStatus2Change($event,$login,$status2){
    global $tako_state;

    if (!$tako_state['running']) return;

    if ($status2 < 2){
        return;
    }

    if (function_exists('ml_timesUpdateXmlF')){
        // from plugin.16.ml_times.php, this means tako should be later in the plugin priority.
        ml_timesUpdateXmlF($login, 'hide'); // hides the big records panel in podium screen, as it conflicts with tako panel.
    }
}


function takoEverytime($event){
    global $tako_state, $tako_config;

    if (!$tako_state['running']) return;

    // Only update if enough time has passed
    $now = microtime(true);
    $diff = $now - $tako_state['xmlLastUpdate'];
    if ($tako_state['xmlNeedsUpdate'] && $diff >= $tako_config['xmlUpdateInterval']) {
        $tako_state['xmlLastUpdate'] = $now;
        $tako_state['xmlNeedsUpdate'] = false;

        tako_updateXml(true, 'show');
    }
}



// ----------------------
// CHAT COMMANDS
// ----------------------
function chat_tako($author, $login, $params, $params2=null){
	global $_StatusCode, $tako_commandAliases;

	// Is author admin?
	if(!verifyAdmin($login)){
		return;
    }

	// if changing map or sync then delay the command !
	if($_StatusCode <= 3){
		addEventDelay(300,'Function','chat_tako',$author, $login, $params);
		return;
	}

    $cmd = strtolower($params[0]);

    // Apply any aliases
    if (isset($tako_commandAliases[$cmd])) {
        $cmd = $tako_commandAliases[$cmd];
    }

    switch($cmd){
        case 'on':
            tako_commandOn($login);
            break;

        case 'off':
            tako_commandOff($login);
            break;

        case 'pause':
            tako_commandPause($login);
            break;

        case 'elim':
            tako_commandElim($login, $params, $params2);
            break;

        default:
            tako_sendHelp($login);
            break;
    }
}


function tako_commandOn($login) {
    global $tako_state;
    if ($tako_state['running']) {
        $msg = localeText(null, 'server_message') . localeText(null, 'interact') .
               "/tako on - Error - TA KO is already running!";
        addCall(null, 'ChatSendToLogin', $msg, $login);
    } else {
        tako_startGame($login);
    }
}


function tako_commandOff($login) {
    global $tako_state;
    if (!$tako_state['running']) {
        $msg = localeText(null, 'server_message') . localeText(null, 'interact') .
               "/tako off - Error - TA KO is already off!";
        addCall(null, 'ChatSendToLogin', $msg, $login);
    } else {
        tako_stopGame($login);
    }
}


function tako_commandPause($login) {
    global $tako_config, $tako_state, $_players;

    $tako_config['elimPaused'] = !$tako_config['elimPaused'];

    $nick = $_players[$login]['NickName'];
    $msg = localeText(null,'server_message').$nick.localeText(null,'interact')." (admin) ";
    $tako_config['elimPaused'] ? $msg .= "paused TA KO." : $msg .= "resumed TA KO!";
    tako_sendChat($msg);
    
    $tako_state['xmlNeedsUpdate'] = true;
}


function tako_commandElim($login, $params, $params2) {
    global $tako_config, $tako_state, $tako_elimThresholdsPresets, $_players;

    // /tako elim custom
    if (isset($params2[1]) && $params2[1] === 'custom') {
        $args = array_slice($params2, 2);
        tako_parseElimThresholds($args, $login);
        return;
    }

    // /tako elim <preset> or <number>
    if (isset($params[1])) {
        $arg = strtolower(trim($params[1]));
        $matchedPreset = false;

        // Check presets
        foreach ($tako_elimThresholdsPresets as $presetName => $preset) {
            if ($arg === strtolower($presetName)) {
                $tako_config['elimThresholds'] = $preset;
                tako_updateElimCountByThreshold();
                $tako_state['xmlNeedsUpdate'] = true;
                tako_printCurrentThresholds($login, $arg);
                $matchedPreset = true;
                return;
            }
        }

        // Check number
        if (!$matchedPreset && is_numeric($arg)) {
            $num = intval($arg);
            console("elim numeric triggered: $num");

            if ($num >= $tako_config['minElims'] && $num <= $tako_config['maxElims']) {
                $tako_config['elimThresholds'] = array(); // disable thresholds

                $tako_config['elimCount'] = $num;
                $nick = $_players[$login]['NickName'];
                $msg = localeText(null,'server_message').$nick.localeText(null,'interact')." (admin) set TA KO eliminations per map to $num";
                tako_sendChat($msg);

                if ($tako_state['running']) {
                    $tako_state['xmlNeedsUpdate'] = true;
                }
            } else {
                $msg = localeText(null, 'server_message') . localeText(null, 'interact') .
                       "/tako elim: Enter a valid number, min " . $tako_config['minElims'] .
                       ", max " . $tako_config['maxElims'];
                addCall(null, 'ChatSendToLogin', $msg, $login);
            }

            return;
        }

        // Unknown arg
        if (!$matchedPreset) {
            $msg = localeText(null, 'server_message') . localeText(null, 'interact') .
                   "/tako elim: Unknown argument '$arg'.";
            addCall(null, 'ChatSendToLogin', $msg, $login);
        }
    }

    // No arguments: show help
    $presetsText = '';
    foreach ($tako_elimThresholdsPresets as $presetName => $preset){
        $presetsText .= '|' . $presetName;
    }
    $currentElimSettingText = '';
    if (empty($tako_config['elimThresholds'])){
        $currentElimSettingText .= "Current elim setting: " .
           strval($tako_config['elimCount'] . " elim per map (num)");
    } else {
        $pairs = array();
        foreach ($tako_config['elimThresholds'] as $th => $val) {
            $pairs[] = "$th=$val";
        }
        $currentElimSettingText .= "Current elim setting: " . implode(" > ", $pairs) . " (thresholds)";
    }
    $msg = localeText(null, 'server_message') . localeText(null, 'interact') .
           "Usage: /tako elim [num 1-7]" . $presetsText . "|custom\n" . $currentElimSettingText;
    addCall(null, 'ChatSendToLogin', $msg, $login);
}


function tako_sendHelp($login) {
    $msg = localeText(null, 'server_message') . localeText(null, 'interact') .
           "/tako on|start\n/tako off|stop\n/tako pause|p (Toggles pausing eliminations, for rs/next map)\n/tako elim|e [num|preset|custom] (Sets elims or thresholds)";
    addCall(null, 'ChatSendToLogin', $msg, $login);
}


function tako_parseElimThresholds($args, $login) {
    global $tako_config, $tako_state;

    if (empty($args)) {
        $msg = localeText(null,'server_message') . localeText(null,'interact')."Sets custom elimination count thresholds.\nUsage: /tako elim custom [alivePlayers=elimAmount] ...\nExample cotd format: \n/tako elim custom 255=4 16=2 8=1";
        addCall(null,'ChatSendToLogin', $msg, $login);
        return;
    }

    $newThresholds = array();

    foreach ($args as $arg) {
        if (strpos($arg, '=') === false) {
            $msg = localeText(null,'server_message') . localeText(null,'interact')."Invalid threshold format: $arg (expected X=Y)";
            addCall(null,'ChatSendToLogin', $msg, $login);
            return;
        }

        list($threshold, $elimCount) = explode('=', $arg, 2);
        $threshold = (int) $threshold;
        $elimCount = (int) $elimCount;

        if ($threshold <= 2 || $elimCount < $tako_config['minElims'] || $elimCount > $tako_config['maxElims']) {
            $msg = localeText(null,'server_message') . localeText(null,'interact')."Invalid values in: $arg";
            addCall(null,'ChatSendToLogin', $msg, $login);
            return;
        }

        $newThresholds[$threshold] = $elimCount;
    }

    if (empty($newThresholds)) {
        $msg = localeText(null,'server_message') . localeText(null,'interact')."No valid thresholds set.";
        addCall(null,'ChatSendToLogin', $msg, $login);
        return;
    }

    // Sort thresholds in descending order
    krsort($newThresholds, SORT_NUMERIC);

    $tako_config['elimThresholds'] = $newThresholds;

    tako_printCurrentThresholds($login);
    tako_updateElimCountByThreshold();
    $tako_state['xmlNeedsUpdate'] = true;
}


function tako_printCurrentThresholds($login, $preset = "custom"){
    global $tako_config, $_players;
    $pairs = array();
    foreach ($tako_config['elimThresholds'] as $th => $val) {
        $pairs[] = "$th=$val";
    }
    $nick = $_players[$login]['NickName'];
    $msg = localeText(null,'server_message').$nick.localeText(null,'interact')." (admin) set TA KO elimination thresholds to '$preset': \n" . implode(' ', $pairs);
    tako_sendChat($msg);
}



// ----------------------
// MAIN LOGIC
// ----------------------
function tako_startGame($login) {
    global $tako_state, $tako_config, $_players, $_StatusCode;

    $tako_state['running'] = true;
    $tako_state['players'] = array();
    $tako_state['mapNumber'] = 1;
    $tako_state['entryOpen'] = true;

    $hideTimeUpdateXmlF = function_exists('ml_timesUpdateXmlF') && $_StatusCode === 5;
    foreach ($_players as $login2 => $p){
        if($p['Active']) tako_addPlayer($login2, false);

        if ($hideTimeUpdateXmlF){
            // from plugin.16.ml_times.php, this means tako should be later in the plugin priority.
            ml_timesUpdateXmlF($login, 'hide'); // hides the big records panel in podium screen, as it conflicts with tako panel.
        }
    }
    tako_sortPlayers($tako_state['players']);

    tako_logStandings(false, "\n" . date('m/d,H:i:s') . " - TA KO started\n");

    $nick = $_players[$login]['NickName'];
    $msg = localeText(null,'server_message').$nick.localeText(null,'interact')." (admin) started TA KO! Eliminations per map: " . strval($tako_config['elimCount']);
    tako_sendChat($msg);
}


function tako_stopGame($login = false) {
    global $_players;

    tako_resetState();
    tako_updateXml(true, 'remove');
    tako_logStandings(false, "\n" . date('m/d,H:i:s') . " - TA KO stopped\n\n\n");

    if ($login === false){
        $msg = localeText(null,'server_message').localeText(null,'interact')."TA KO finished. Thanks for playing!";
        tako_sendChat($msg);
        return;
    }
    $nick = $_players[$login]['NickName'];
    $msg = localeText(null,'server_message').$nick.localeText(null,'interact')." (admin) stopped TA KO.";
    tako_sendChat($msg);
}


function tako_processEliminations() {
    global $tako_state, $tako_config;

    // Make sure players are sorted first
    tako_sortPlayers($tako_state['players'], false);
    // $tako_state['xmlNeedsUpdate'] = true;

    // Are eliminations paused?
    if ($tako_config['elimPaused']){
        console("TAKO: eliminations paused, skipping eliminations");
        return;
    }

    // Collect alive players in sorted order
    $alivePlayers = array();
    foreach ($tako_state['players'] as $login => $p) {
        if (isset($p['status']) && $p['status'] === 'alive') {
            $alivePlayers[$login] = $p;
        }
    }

    $aliveCount = count($alivePlayers);
    if ($aliveCount === 0) {
        console("TAKO: no alive players, nothing to eliminate.");
        return;
    }

    // Split into with-time and no-time groups
    $withTime = array();
    $noTime  = array();
    foreach ($alivePlayers as $login => $p) {
        if (!isset($p['bestTime']) || $p['bestTime'] === null) {
            $noTime[$login] = $p;
        } else {
            $withTime[$login] = $p;
        }
    }

    // If ALL alive players have no time, do nothing
    if (count($noTime) === $aliveCount) {
        $msg = localeText(null,'server_message').localeText(null,'interact')."TA KO: Nobody set a time, no eliminations.";
        tako_sendChat($msg);
        console("TAKO: nobody finished, no eliminations.");
        return;
    }

    // Start with eliminating all no-time players (they count toward elimCount)
    $elimPlayers = $noTime;

    // Fill remaining slots (if any) with the slowest from with-time group
    $desiredElims = ($tako_config['elimCount'] >= $aliveCount) ? $aliveCount -1 : $tako_config['elimCount']; // Keep at least one player alive
    $remainingSlots = $desiredElims - count($noTime);
    if ($remainingSlots > 0 && count($withTime) > 0) {
        // withTime is in sorted order fastest->slowest, take last N:
        $elimFromSorted = array_slice($withTime, -$remainingSlots, null, true);
        foreach ($elimFromSorted as $login => $p) {
            $elimPlayers[$login] = $p;
        }
    }

    // Assign finalPosition and apply elimination
    $finalBase = $aliveCount;
    $offset = 0;
    tako_sortPlayers($elimPlayers, false);
    foreach (array_reverse($elimPlayers) as $login => $p) {
        $rank = $finalBase - $offset;
        $tako_state['players'][$login]['status'] = 'eliminated';
        $tako_state['players'][$login]['elimRound'] = strVal($tako_state['mapNumber']);
        $tako_state['players'][$login]['finalPosition'] = $rank;
        $offset++;
    }

    // Announce elims
    $elimNicks = localeText(null,'interact').' -  ';
    foreach ($elimPlayers as $login => $p){
        $elimNicks .= $p['nick'] . localeText(null,'interact').'  -  ';
    }
    $msg = localeText(null,'server_message').localeText(null,'interact')."TA KO: Eliminated players: " . (count($elimPlayers) ? $elimNicks : '(none)');
    tako_sendChat($msg);

    // Someone got eliminated, close entry
    if ($tako_state['entryOpen'] && count($elimPlayers) > 0) {
        $tako_state['entryOpen'] = false;
        $msg = localeText(null,'server_message').localeText(null,'interact')."TA KO: Entry to the competition is now closed!";
        tako_sendChat($msg);
    }

    // Update alivePlayers after eliminations
    foreach ($elimPlayers as $login => $p) {
        unset($alivePlayers[$login]);
    }

    // Check for a winner — use foreach to safely get the first (and only) remaining key
    if (count($alivePlayers) === 1) {
        $winnerLogin = null;
        foreach ($alivePlayers as $login => $p) { $winnerLogin = $login; break; }

        if ($winnerLogin !== null && $winnerLogin !== false && $winnerLogin !== '') {
            $msg = localeText(null,'server_message').localeText(null,'interact')."TA KO: And the winner is... " . $alivePlayers[$winnerLogin]['nick'] . localeText(null,'interact') .' !';
            tako_sendChat($msg);
            console("TAKO: Winner is (" . $winnerLogin . ")");

            // Assign finalPosition=1 to the winner
            $tako_state['players'][$winnerLogin]['finalPosition'] = 1;

            //Log final result
            tako_logStandings(true);
            tako_logHtmlResults();

            //Stop the plugin
            tako_stopGame();
            return;
        } else {
            // Debug output if something unexpected happened
            console("TAKO DEBUG: could not determine winner login. alivePlayers:\n" . print_r($alivePlayers, true));
            return;
        }
    }

    // Log standings
    tako_logStandings();

    // Advance map counter
    $tako_state['mapNumber']++;
}


function tako_addPlayer($login, $sort = true){
    global $tako_state, $_players;
    
    if (!$tako_state['running']) return;

    if (!isset($tako_state['players'][$login])) {
        // If first map => alive, otherwise => spectator
        $status = ($tako_state['entryOpen']) ? 'alive' : 'spectator';

        // get nickname with colors
        $nick = '';
        if (isset($_players[$login])){
            $nick = $_players[$login]["NickName"];
        } else {
            $nick = $login;
        }        

        $tako_state['players'][$login] = array(
            'login'             => $login,
            'nick'              => $nick,
            'status'            => $status, // 'alive', 'eliminated' or 'spectator'
            'bestTime'          => null,
            'bestTimeTimestamp' => null,
            'rank'              => null,
            'finalPosition'     => null,
            'elimRound'         => null,
        );

        if ($tako_state['entryOpen']){
            tako_updateElimCountByThreshold();
        }

        if ($sort){
            tako_sortPlayers($tako_state['players']);
        }
    }
}


function tako_compare_players($a, $b) {
    // Comparator for sorting the players

    // 1. Group by status priority
    $statusOrder = array(
        'alive'      => 1,
        'eliminated' => 2,
        'spectator'  => 3,
    );

    $sa = isset($statusOrder[$a['status']]) ? $statusOrder[$a['status']] : 99;
    $sb = isset($statusOrder[$b['status']]) ? $statusOrder[$b['status']] : 99;

    if ($sa < $sb) return -1;
    if ($sa > $sb) return 1;

    // 2. Within same status
    if ($a['status'] === 'alive') {
        $aHasTime = (isset($a['bestTime']) && $a['bestTime'] !== null);
        $bHasTime = (isset($b['bestTime']) && $b['bestTime'] !== null);

        if ($aHasTime && !$bHasTime) return -1;
        if (!$aHasTime && $bHasTime) return 1;

        if ($aHasTime && $bHasTime) {
            if ($a['bestTime'] < $b['bestTime']) return -1;
            if ($a['bestTime'] > $b['bestTime']) return 1;

            $at = (isset($a['bestTimeTimestamp']) && $a['bestTimeTimestamp'] !== null) ? $a['bestTimeTimestamp'] : PHP_FLOAT_MAX;
            $bt = (isset($b['bestTimeTimestamp']) && $b['bestTimeTimestamp'] !== null) ? $b['bestTimeTimestamp'] : PHP_FLOAT_MAX;

            if ($at < $bt) return -1;
            if ($at > $bt) return 1;
        }

        // Tie-break by login (alphabetical)
        return strcmp($a['login'], $b['login']);
    }

    if ($a['status'] === 'eliminated') {
        $af = (isset($a['finalPosition']) && $a['finalPosition'] !== null) ? (int)$a['finalPosition'] : PHP_INT_MAX;
        $bf = (isset($b['finalPosition']) && $b['finalPosition'] !== null) ? (int)$b['finalPosition'] : PHP_INT_MAX;

        if ($af < $bf) return -1;
        if ($af > $bf) return 1;
        return 0;
    }

    // Spectators: keep equal (uasort is not stable in PHP5.2, so order not guaranteed)
    return 0;
}


function tako_sortPlayers(&$players, $updateXml = true) {
    global $tako_state;

    uasort($players, 'tako_compare_players');
    
    $count = 1;
    foreach ($players as $login => &$p ){
        $p['rank'] = $count;
        $count++;
    }
    unset($p);

    if ($updateXml){
        $tako_state['xmlNeedsUpdate'] = true;
    }
}


function tako_updateElimCountByThreshold() {
    global $tako_state, $tako_config;

    // Not using thresholds
    if (empty($tako_config['elimThresholds'])){
        return;
    }

    $aliveCount = 0;
    foreach ($tako_state['players'] as $p) {
        if ($p['status'] === 'alive') {
            $aliveCount++;
        }
    }

    $newCount = $tako_config['maxElims']; // Set default highest to maxElims

    $loopCount = 0;
    $size = count($tako_config['elimThresholds']);
    foreach ($tako_config['elimThresholds'] as $threshold => $elim) {
        if ($aliveCount <= $threshold) {
            $newCount = $elim;

            if ($loopCount + 1 === $size){
                $tako_state['nextThreshold'] = -1;
            }

        } else {
            $tako_state['nextThreshold'] = $threshold;
            break;
        }

        $loopCount++;
    }

    // No change
    if ($tako_config['elimCount'] === $newCount){
        return;
    }

    $tako_config['elimCount'] = $newCount;
}


function tako_resetState(){
    global $tako_state;

    $tako_state['running'] = false;
    $tako_state['players'] = array();
    $tako_state['nextThreshold'] = -1;
    $tako_state['mapNumber'] = 0;
    $tako_state['entryOpen'] = true;
    $tako_state['xmlNeedsUpdate'] = false;
    $tako_state['xmlLastUpdate'] = 0;
    $tako_state['xmlSpec'] = array();
}



// ----------------------
// GUI - MANIALINKS
// ----------------------
function tako_updateXml($login,$action='show'){
    global $_players, $tako_config, $tako_state, $tako_specActions;

    if ($login===true){
        foreach ($_players as $login => $val){
            tako_updateXml($login, $action);
        }
        return;
	}

    // Hide
    if ($action === 'hide'){
        manialinksHide($login,'tako.bg');
        manialinksHide($login,'tako.header');
        manialinksHide($login,'tako.rank');
        manialinksHide($login,'tako.0');
        manialinksHide($login,'tako.1');
        manialinksHide($login,'tako.2');
        manialinksHide($login,'tako.3');
        manialinksHide($login,'tako.4');
        manialinksHide($login,'tako.5');
        manialinksHide($login,'tako.6');
        manialinksHide($login,'tako.7');
        manialinksHide($login,'tako.8');
        manialinksHide($login,'tako.9');
        return;
    }

    // Remove
    if ($action === 'remove'){
        manialinksRemove($login,'tako.bg');
        manialinksRemove($login,'tako.header');
        manialinksRemove($login,'tako.rank');
        manialinksRemove($login,'tako.0');
        manialinksRemove($login,'tako.1');
        manialinksRemove($login,'tako.2');
        manialinksRemove($login,'tako.3');
        manialinksRemove($login,'tako.4');
        manialinksRemove($login,'tako.5');
        manialinksRemove($login,'tako.6');
        manialinksRemove($login,'tako.7');
        manialinksRemove($login,'tako.8');
        manialinksRemove($login,'tako.9');
        return;
    }

    // only show if tako is on
    if (!$tako_state['running']){
        return;
    }

    $bgColor = '0015';
    if ($action === 'showSolid'){ // unused
        $bgColor = '222f';
    }

    // show/refresh

    $displayList = tako_getDisplayList($login);

    $xmlFrame = "<frame posn='-64.1 8 20'>"; // Display position

    // Info to strings
    $entry = '$i$o' . 'TA KO';
    $kosThisMap = '$i$o' . 'KOS this map';
    $kosThisMapNumber = tako_safeXml('$i$o' . strval(min($tako_config['elimCount'], count($displayList) - 1)));
    if (!empty($tako_config['elimThresholds']) && $tako_state['nextThreshold'] !== -1){
        $kosThisMapNumber .= " until " . $tako_state['nextThreshold'] . " players";
    }
    $status = '$i$o' . 'Status';
    $statusStatus = '$i$o';
    if (isset($tako_state['players'][$login]) && $tako_state['players'][$login]['status'] !== 'spectator'){
        $statusStatus .= $tako_state['players'][$login]['status'];
    } else {
        $statusStatus .= ($tako_state['entryOpen'] ? 'Entry open' : 'Entry closed');
    }
    $statusStatus = tako_safeXml($statusStatus);
    $rank = '$i$o' . 'Rank';
    $rankNumber = '$i$o';
    if (isset($tako_state['players'][$login]) && $tako_state['players'][$login]['status'] === 'alive'){
        $rankNumber .= strval($tako_state['players'][$login]['rank']);
    }
    else if (isset($tako_state['players'][$login]) && $tako_state['players'][$login]['status'] === 'eliminated'){
        $rank = '$i$o' . 'Final Rank';
        $rankNumber .= strval($tako_state['players'][$login]['finalPosition']);
    } else {
        $rankNumber .= ($tako_state['entryOpen'] ? 'No rank yet' : 'Not Playing');
    }
    $rankNumber = tako_safeXml($rankNumber);


    // Background
    $xmlBg = $xmlFrame;
    $xmlBg .= "<quad posn='0 13 0' sizen='20 12' bgcolor='$bgColor'/>";
    $xmlBg .= "<quad posn='0 0 0' sizen='20 31' bgcolor='$bgColor'/>";

    // Header
    $xmlHeader = $xmlFrame;
    $xmlHeader .= "<label posn='10 12.5 2' sizen='19.6 3' textsize='2' text='$entry' scale='0.9' halign='center'/>";
    $xmlHeader .= "<label posn='0.2 9.5 2' sizen='11 3' textsize='2' text='$kosThisMap' scale='0.8'/>";
    $xmlHeader .= "<label posn='19.8 9.5 2' sizen='11 3' textsize='2' text='$kosThisMapNumber' scale='0.8' halign='right'/>";
    $xmlHeader .= "<label posn='0.2 6.5 2' sizen='19.6 3' textsize='2' text='$status' scale='0.8'/>";
    $xmlHeader .= "<label posn='19.8 6.5 2' sizen='19.6 3' textsize='2' text='$statusStatus' scale='0.8' halign='right'/>";
    $xmlRank = $xmlFrame;
    $xmlRank .= "<label posn='0.2 3.5 2' sizen='19.6 3' textsize='2' text='$rank' scale='0.8'/>";
    $xmlHeader .= "<label posn='19.8 3.5 2' sizen='19.6 3' textsize='2' text='$rankNumber' scale='0.8' halign='right'/>";


    // Player slots
    $vPos = -0.5;
    $displayListCount = count($displayList);
    $loopCount = 0;
    foreach ($displayList as $row => $pl){
        $xml = $xmlFrame;
        // store logins for action callback
        $tako_state['xmlSpec'][$login][$loopCount] = $pl['login'];

        $rank = tako_safeXml('$i$o' . $pl['rank']);
        $nick = tako_safeXml('$s'. $pl['nick']);
        $time = tako_safeXml($pl['color'] . '$s$i' . MwTimeToString($pl['bestTime']));
        
        $vPosRank = $vPos - 0.1;
        $vPosTime = $vPos - 0.2;

        $xml .= "<label posn='0.2 $vPosRank 2' sizen='3 3' textsize='2' text='$rank' scale='0.8'/>";
        $xml .= "<label posn='3.2 $vPos 2' sizen='11.5 3' textsize='2' text='$nick' scale='0.9'/>";
        $xml .= "<label posn='19.7 $vPosTime 2' sizen='7 3' textsize='2' text='$time' halign='right' scale='0.8'/>";

        $action = $tako_specActions[$loopCount];
        $vPosHighlight = $vPos + 0.5;
        if ($pl['login'] === $login){
            $xml .= "<quad posn='0 $vPosHighlight 1' sizen='20 3' bgcolor='0015' action='$action'/>";
        } else {
            $xml .= "<quad posn='0 $vPosHighlight 1' sizen='20 3' bgcolor='0000' action='$action'/>";
        }

        $vPos -= 3;

        // elim divider, attach to header
        if (max(1, $displayListCount - $tako_config['elimCount']) === $row + 1){
            $xmlHeader .= "<quad posn='0.5 $vPos 2' sizen='19 0.1' bgcolor='fffc'/>";
            $vPos -= 1;
        }

        $xml .= "</frame>";

        manialinksShow($login, 'tako.'.strval($loopCount), $xml);

        $loopCount++;        
    }
    for ($i = $loopCount; $i < $tako_config['maxDisplaySlots']; $i++){ // empty remaining slots
        manialinksHide($login, 'tako.'.strval($i));
    }

    // paused, attach to background
    if ($tako_config['elimPaused']){
        $pauseText = '$i$o' . 'Eliminations Paused';
        $xmlBg .= "<quad posn='0 13 3' sizen='20 44' bgcolor='222c'/>";
        $xmlBg .= "<label posn='10 -9 3' sizen='18 31' halign='center' valign='center' textsize='2' text='$pauseText'/>";
    }

    // end xml
    $xmlBg .= "</frame>";
    $xmlHeader .= "</frame>";
    $xmlRank .= "</frame>";

    manialinksShow($login, 'tako.bg', $xmlBg);
    manialinksShow($login, 'tako.header', $xmlHeader);
    manialinksShow($login, 'tako.rank', $xmlRank);
}


function tako_addDisplayPlayer(&$display, &$usedLogins, $player, $color = '$0d8') {
    // helper for getDisplayList
    if ($player && !isset($usedLogins[$player['login']])) {
        $player['color'] = $color;
        $display[] = $player;
        $usedLogins[$player['login']] = true;
    }
}


function tako_compare_displayList($a, $b){
    // comparator for getDisplayList
    if (isset($a['rank']) && isset($b['rank'])){
        return (intval($a['rank']) < intval($b['rank'])) ? -1 : 1;
    }
    return 0;
}


function tako_getDisplayList($mainLogin) {
    // Builds the display list of players for a given main player.
    global $tako_state, $tako_config;

    $alivePlayers = array();
    foreach ($tako_state['players'] as $p) {
        if ($p['status'] === 'alive') {
            $alivePlayers[] = $p;
        }
    }

    // Nothing to show
    if (empty($alivePlayers)) {
        return array();
    }

    $elimCount = $tako_config['elimCount'];
    $aliveCount = count($alivePlayers);
    $maxSlots = $tako_config['maxDisplaySlots'];

    // Assign ranks
    for ($i = 0; $i < $aliveCount; $i++) {
        $alivePlayers[$i]['rank'] = strval($i + 1);
    }

    // Shrink slot count if fewer players remain
    if ($aliveCount < $maxSlots) {
        $maxSlots = $aliveCount;
    }

    $display = array();
    $usedLogins = array();

    // Danger zone (last elimCount players, red)
    $dangerStart = max(1, $aliveCount - $elimCount);
    for ($i = $dangerStart; $i < $aliveCount; $i++) {
        tako_addDisplayPlayer($display, $usedLogins, $alivePlayers[$i], '$f54');
    }

    // Bubble (player just above danger zone, orange)
    $bubbleIndex = $dangerStart - 1;
    if ($bubbleIndex >= 0) {
        tako_addDisplayPlayer($display, $usedLogins, $alivePlayers[$bubbleIndex], '$fd0');
    }

    // Top 1 player
    for ($i = 0; $i < min(1, $aliveCount); $i++) {
        if (count($display) >= $maxSlots) break;
        tako_addDisplayPlayer($display, $usedLogins, $alivePlayers[$i]);        
    }

    // Ensure the main player is visible
    $mainIndex = -1;
    foreach ($alivePlayers as $i => $p) {
        if ($p['login'] === $mainLogin) {
            $mainIndex = $i;
            break;
        }
    }

    if ($mainIndex !== -1 && !isset($usedLogins[$mainLogin])) {
        // Insert main player + neighbor context
        tako_addDisplayPlayer($display, $usedLogins, $alivePlayers[$mainIndex]);

        if (isset($alivePlayers[$mainIndex - 1]) && count($display) < $maxSlots) {
            tako_addDisplayPlayer($display, $usedLogins, $alivePlayers[$mainIndex - 1]);
        }
        if (isset($alivePlayers[$mainIndex + 1]) && count($display) < $maxSlots) {
            tako_addDisplayPlayer($display, $usedLogins, $alivePlayers[$mainIndex + 1]);
        }
    }

    // Top 2 and 3 players
    for ($i = 1; $i < min(3, $aliveCount); $i++) {
        if (count($display) >= $maxSlots) break;
        tako_addDisplayPlayer($display, $usedLogins, $alivePlayers[$i]);        
    }

    // Fill remaining slots with slowest unused players
    if (count($display) < $maxSlots) {
        foreach (array_reverse($alivePlayers) as $p) {
            if (count($display) >= $maxSlots) break;
            tako_addDisplayPlayer($display, $usedLogins, $p);
        }
    }

    usort($display, 'tako_compare_displayList');

    return $display;
}



// ----------------------
// LOGGING
// ----------------------
function tako_logStandings($finalRanking = false, $customMessage = 'none') {
    global $tako_state, $_Game, $_DedConfig, $_ChallengeInfo;

    // Open file in append mode (create if it doesn't exist)
    $filepath = 'fastlog/tako.log.'.strtolower($_Game).'.'.$_DedConfig['login'].'.txt';
    $fp = @fopen($filepath, 'ab');
    if (!$fp) {
        console("TAKO ERROR: Could not open log file: " . $filepath);
        return false;
    }

    $output = '';
    if ($customMessage === 'none'){
        // Make sure players are sorted before logging
        tako_sortPlayers($tako_state['players'], false);

        $cuid = isset($_ChallengeInfo['UId']) ? $_ChallengeInfo['UId'] : 'UID' ;
        $mapNum = strVal($tako_state['mapNumber']);

        $header = '';
        if ($finalRanking){
            $header .= "\n=== TAKO Final result ===";
        }
        $header .= "\n" . date('m/d,H:i:s');
        $header .= "\ntako map $mapNum on [".stripColors($_ChallengeInfo['Name']).'] ('.$_ChallengeInfo['Environnement'].','.$cuid.','.stripColors($_ChallengeInfo['Author']).')';
        
        $lines  = "\n";
        foreach ($tako_state['players'] as $login => $p) {
            $s_login = (isset($p['login']) ? $p['login'] : $login);
            $nick = (isset($p['nick']) ? $p['nick'] : $login);
            $status = (isset($p['status']) ? $p['status'] : 'null');
            $bestTime = (isset($p['bestTime']) ? $p['bestTime'] : 'null');
            //$bestTimeTimestamp = (isset($p['bestTimeTimestamp']) ? $p['bestTimeTimestamp'] : 'null');
            $rank = (isset($p['rank']) ? $p['rank'] : 'null');
            $finalPosition = (isset($p['finalPosition']) ? $p['finalPosition'] : 'null');
            $elimRound = (isset($p['elimRound']) ? $p['elimRound'] : 'null');

            $nick = tako_htmlEscapeCommas($nick);

            $lines .= $rank.','.$finalPosition.','.$s_login.','.$status.','.$elimRound.','.MwTimeToString($bestTime).','.$bestTime.','.stripColors($nick).','.$nick."\n";
        }
        $output = $header . $lines;
    } 
    else {
        $output = $customMessage;
    }


    // Write to file
    fwrite($fp, $output);
    fclose($fp);

    console("TAKO: Write to log: " . $filepath);
    return true;
}


function tako_logHtmlResults($scale = 1.5) {
    global $tako_state, $tako_config, $_Game, $_DedConfig;

    if (!$tako_config['generateHtmlResult']){
        return;
    }

    // Ensure folder exists
    $folder = 'fastlog/htmlResults';
    if (!is_dir($folder)) {
        @mkdir($folder, 0777, true);
    }

    // Generate unique filename
    $timestamp = date('Y-m-d_H-i-s');
    $filepath = $folder . '/tako_' . strtolower($_Game) . '.' . $_DedConfig['login'] . '.' . $timestamp . '.html';

    // Sort players
    tako_sortPlayers($tako_state['players'], false);
    $dateStr = date('Y-m-d H:i:s');

    // Build player list (skip spectators)
    $players = array();
    foreach ($tako_state['players'] as $login => $p) {
        if (isset($p['status']) && $p['status'] === 'spectator') continue;
        $players[] = array('login' => $login) + $p;
    }

    $totalPlayers = count($players);
    $maxRowsPerTable = 25;
    $numTables = ceil($totalPlayers / $maxRowsPerTable);
    $chunks = array_chunk($players, $maxRowsPerTable);

    // Style variables
    $fontSize = 12 * $scale;
    $rowHeight = 20 * $scale;
    $headerHeight = $rowHeight * 2;

    // Start HTML
    $html  = "<!DOCTYPE html>\n<html lang='en'>\n<head>\n";
    $html .= "<meta charset='UTF-8'>\n<title>TA KO Results</title>\n";
    $html .= "<style>
        body {
            font-family: Arial, sans-serif;
            background: #222;
            color: #eee;
            padding: 20px;
            text-align: center;
        }
        h1 { margin-bottom: 5px; }
        .subtitle { color: #aaa; font-size: " . (12 * $scale) . "px; margin-bottom: 20px; }
        .tables-container {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
        }
        table {
            width: " . (280 * $scale) . "px;
            border-collapse: collapse;
            background: #333;
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 0 10px rgba(0,0,0,0.4);
            font-size: {$fontSize}px;
        }
        th, td {
            height: {$rowHeight}px;
            padding: 0 6px;
            border-bottom: 1px solid #444;
            text-align: center;
        }
        th {
            background: #444;
            font-weight: bold;
            height: {$headerHeight}px;
            font-size: " . (13 * $scale) . "px;
        }
        tr:nth-child(even) { background: #2a2a2a; }
        tr:hover { background: #3a3a3a; }
        .nick { text-align: left; }
    </style>\n";
    $html .= "</head>\n<body>\n";

    $html .= "<h1>TA KO Results</h1>\n";
    $html .= "<div class='subtitle'>Finished: $dateStr — Total players: $totalPlayers</div>\n";
    $html .= "<div class='tables-container'>\n";

    foreach ($chunks as $chunk) {
        // Fill empty rows if needed to match table height
        $emptyCount = $maxRowsPerTable - count($chunk);
        for ($i = 0; $i < $emptyCount; $i++) {
            $chunk[] = null;
        }

        $html .= "<table>\n";
        $html .= "  <thead>\n";
        $html .= "    <tr><th>Rank</th><th>Nickname</th><th>Login</th><th>ElimMap</th></tr>\n";
        $html .= "  </thead>\n<tbody>\n";

        foreach ($chunk as $p) {
            if ($p === null) {
                $html .= "<tr><td></td><td></td><td></td><td></td></tr>\n";
                continue;
            }

            $rank = isset($p['finalPosition']) ? $p['finalPosition'] : '-';
            $nick = isset($p['nick']) ? $p['nick'] : $p['login'];
            $elimRound = isset($p['elimRound']) ? $p['elimRound'] : '-';
            if ($rank == 1) $elimRound = 'Winner';

            $styledNick = tako_convertTmToHtml($nick);

            $html .= "<tr><td>$rank</td><td class='nick'>$styledNick</td><td>{$p['login']}</td><td>$elimRound</td></tr>\n";
        }

        $html .= "</tbody>\n</table>\n";
    }

    $html .= "</div>\n</body>\n</html>";

    // Write file
    $fp = @fopen($filepath, 'wb');
    if (!$fp) {
        console("TAKO ERROR: Could not write HTML results file: " . $filepath);
        return false;
    }
    fwrite($fp, $html);
    fclose($fp);

    console("TAKO: HTML results written to $filepath");
    return true;
}



// ----------------------
// UTILS
// ----------------------
function tako_sendChat($stringMsg){
	addCall(null,'ChatSendServerMessage', $stringMsg);
}


function tako_safeXml($str) {
    // Don’t escape style codes (like $i, $f00, $o, etc)
    // but do escape anything else dangerous for XML.
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}


function tako_convertTmToHtml($str) {
    $str = tako_safeXml($str);
    // Convert basic $fff color codes → span with color
    $str = preg_replace_callback('/\$([0-9a-fA-F]{3})/', function($m) {
        return '<span style="color:#'.$m[1].'">';
    }, $str);

    // Close spans when a new code starts or string ends
    $str = str_replace('$$', '$', $str); // escaped $
    $str .= str_repeat('</span>', substr_count($str, '<span'));

    // Remove unsupported style codes ($o $n etc.) for simplicity
    $str = preg_replace('/\$(o|i|w|n|s|g|z|l)/i', '', $str);

    return $str;
}


function tako_htmlEscapeCommas($str) {
    return str_replace(',', '&#44;', $str);
}


?>
