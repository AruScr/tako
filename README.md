# TAKO — TrackMania Forever Time Attack Knockout Plugin for FAST 3.2

**TAKO** is a plugin for the [**FAST**](https://github.com/Slig06/Fast3) server controller that turns regular **Time Attack** mode into a knockout competition.  
After each map, the slowest players are eliminated until only one winner remains.

---

## Installation

1. Copy `plugin.93.tako.php` into your FAST installation’s `/plugins` folder.  
2. Restart FAST.

---

## Setup & Usage

TAKO does not modify the game mode, matchsettings, or map list so... you must load your own Time Attack matchsettings manually.  
Time Atack KO feels best with short maps and a short TA Timelimit.

- Example flow:
  1. Load your maps/matchsettings. eg `/map matchsettings`
  2. Configure TAKO eliminations using `/tako elim ...`.
  3. Skip to your first map. eg `/adm next`.
  4. On the podium screen or at the start of the map, type `/tako on`.

---

## Admin Chat Commands

Admins can use the following commands in chat:

### `/tako on`
Start a TAKO match.  
Use this at the start of your first map.

### `/tako off`
Stop a TAKO match.  
*Note: this does not save the current state. TAKO automatically stops when a winner is chosen.*

### `/tako pause`
Toggle pause mode.  
While paused, no players will be eliminated. Useful when restarting or skipping a map.

### `/tako elim [num] | [preset] | custom`
Set how many players are eliminated each round.

| Option | Description | Example |
|---------|-------------|----------|
| `[num]` | Fixed number of eliminations per map (min 1 – max 7). | `/tako elim 4` |
| `[preset]` | A preset threshold configuration. | `/tako elim smooth` |
| `custom` | Define custom elimination thresholds. Format is [alivePlayers]=[elimAmount] separated by a space. 255 is used to represent the maximum players on a server. | `/tako elim custom 255=6 64=4 16=2 8=1` |

**Available presets:** `smooth`, `ktlc`, `cotd`  
(Presets are defined in `takoInit()`. You can add more presets there)

**Command aliases:**
- `/tako on` → `/tako start`
- `/tako off` → `/tako stop`
- `/tako pause` → `/tako p`
- `/tako elim` → `/tako e`

---

## Game Logic

- Players are eliminated at the end of each race, before the podium screen.  
- If TAKO is paused, no eliminations occur that round.  
- Players with no recorded time are automatically eliminated, even if that exceeds the configured limit.  
- **Tie-breaking rules:**
  1. Faster time ranks higher.  
  2. If tied, the player who set the time earlier ranks higher.  
  3. If still tied, login name (alphabetical) is used.  

Eliminated players can continue driving and setting times, but their results no longer count.  
TAKO does not force eliminated players into spectator mode.

---

## Display

TAKO shows a per-player HUD.  
The header displays eliminations this map and the viewing player's status (alive, eliminated, specating) and current rank.  
The body has 10 slots, that are dynamically filled to show the top player, the danger zone players, the bubble player, the viewing player and finally it fills with players near the viewing player.  
Each slot shows rank, player nickname and player best time.

Spectators can click on players in the TAKO HUD to spectate them.

---

## Logging

After each map, current standings are logged.  
After a TAKO match concludes, final results are logged to a text file.

**Text log location:**
`fastlog/tako.log.<game>.<servername>.txt`

**Log format:**
`currentRank,finalRank,login,status,eliminatedRound,bestTime(formatted),bestTime(ms),nickname(text),nickname(colored)`

---

## HTML Results

When a TAKO match ends, a formatted HTML file is generated for easy viewing and sharing.

**Location:**
`fastlog/htmlResults/tako_<game>.<servername>.<datetime>.html`

Open this file in a browser to view or screenshot the results table.

To disable HTML generation, modify this line in `takoInit()`:
```php
$tako_config = array(
    ...
    'generateHtmlResult' => false,
);

