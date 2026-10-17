<?php

declare(strict_types=1);

namespace Semitexa\Music\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\CspNonce;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Music\Application\Payload\Request\MusicAppPayload;
use Semitexa\Ssr\Application\Service\Asset\AssetManager;

/**
 * Renders the music player — a self-contained dark page embedded as an OS
 * dialog, tinted to the OS navy palette (same approach as the tic-tac-toe
 * dialog). The playlist is the bundled "Semitexa Ambient" set served from this
 * package's Static/audio via the versioned asset pipeline.
 *
 * The player is also a process-registry producer: while playing it reports
 * `music:player` with the REAL track position as progress (an honest bar),
 * pausing completes the process — so Chill's Processes panel shows what is
 * playing without knowing anything about this app.
 */
#[AsPayloadHandler(payload: MusicAppPayload::class, resource: ResourceResponse::class)]
final class MusicAppHandler implements TypedHandlerInterface
{
    /** file basename (Static/audio/<file>.ogg) → display title */
    private const TRACKS = [
        'midnight-navy' => 'Midnight Navy',
        'cyan-drift' => 'Cyan Drift',
        'low-orbit' => 'Low Orbit',
        'warm-circuit' => 'Warm Circuit',
    ];

    public function handle(MusicAppPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $tracks = [];
        foreach (self::TRACKS as $file => $title) {
            $tracks[] = ['title' => $title, 'url' => AssetManager::getUrl('audio/' . $file . '.ogg', 'music')];
        }
        // JSON_HEX_TAG: the list now sits in a data block, where a "</script>"
        // inside a value would end it.
        $tracksJson = json_encode($tracks, JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG);
        $playerUrl = AssetManager::getUrl('js/music-player.js', 'music');

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Music · Semitexa</title>
<style>
  :root {
    color-scheme: dark;
    --font: 'IBM Plex Sans', system-ui, sans-serif;
    --text: #eaf2ff; --muted: #8d9bb8;
    --page: #0a1a2f; --panel: #0f2136; --line: rgba(148,163,184,.16);
    --accent: #37b7ff; --accent-soft: rgba(55,183,255,.14);
  }
  /* Light mode — follows the OS shell: the shell resolves its 3-way theme to a
     concrete data-skin-mode on ITS <html>; same-origin app iframes mirror that
     attribute here (see syncTheme()). Colors match the shell's light tokens. */
  :root[data-skin-mode="light"] {
    color-scheme: light;
    --text: #16222e; --muted: #55677e;
    --page: #e8eef7; --panel: #ffffff; --line: rgba(100,116,139,.28);
    --accent: #1e7fb8; --accent-soft: rgba(30,127,184,.12);
  }
  * { box-sizing: border-box; }
  html, body { margin: 0; height: 100%; background: var(--page); }
  /* The dialog window must NEVER grow a scrollbar of its own: the page is a
     fixed column and ONLY the playlist scrolls (with the custom slim bar). */
  body { font-family: var(--font); color: var(--text); display: flex; justify-content: center; padding: 16px; overflow: hidden; }
  .mp { width: 100%; max-width: 460px; height: 100%; min-height: 0; display: flex; flex-direction: column; gap: 14px; }
  .mp__head { display: flex; align-items: baseline; justify-content: space-between; }
  .mp__title { font-weight: 700; font-size: 16px; }
  .mp__title small { color: var(--muted); font-weight: 500; font-size: 11.5px; margin-left: 8px; }
  .mp__now { background: var(--panel); border: 1px solid var(--line); border-radius: 14px; padding: 14px 16px; display: flex; flex-direction: column; gap: 10px; }
  .mp__track { font-size: 15px; font-weight: 600; min-height: 20px; }
  .mp__bar { display: flex; align-items: center; gap: 10px; font-size: 11px; color: var(--muted); font-variant-numeric: tabular-nums; }
  .mp__seek { flex: 1; accent-color: var(--accent); height: 4px; cursor: pointer; }
  .mp__ctl { display: flex; align-items: center; gap: 10px; }
  .mp__btn { font: inherit; width: 40px; height: 40px; border-radius: 50%; border: 1px solid var(--line);
    background: var(--panel); color: var(--text); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 15px; }
  .mp__btn:hover { background: var(--accent-soft); border-color: var(--accent); }
  .mp__btn--play { width: 52px; height: 52px; background: var(--accent-soft); border-color: var(--accent); font-size: 19px; }
  .mp__vol { margin-left: auto; width: 90px; accent-color: var(--accent); }
  .mp__list { flex: 1; min-height: 0; overflow-y: auto; display: flex; flex-direction: column; gap: 6px; padding-right: 6px;
    scrollbar-width: thin; scrollbar-color: rgba(55,183,255,.45) transparent; }
  .mp__list::-webkit-scrollbar { width: 6px; }
  .mp__list::-webkit-scrollbar-track { background: transparent; }
  .mp__list::-webkit-scrollbar-thumb { background: rgba(55,183,255,.35); border-radius: 999px; }
  .mp__list::-webkit-scrollbar-thumb:hover { background: rgba(55,183,255,.65); }
  .mp__item { flex: 0 0 auto; }
  .mp__item { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border: 1px solid var(--line); border-radius: 12px;
    background: transparent; color: var(--text); font: inherit; font-size: 13.5px; cursor: pointer; text-align: left; }
  .mp__item:hover { background: var(--accent-soft); }
  .mp__item.active { border-color: var(--accent); background: var(--accent-soft); }
  .mp__item .eq { width: 14px; display: inline-flex; gap: 2px; align-items: flex-end; height: 12px; visibility: hidden; }
  .mp__item.active.playing .eq { visibility: visible; }
  .eq i { width: 3px; background: var(--accent); animation: eq .9s infinite ease-in-out; }
  .eq i:nth-child(1) { height: 60%; } .eq i:nth-child(2) { height: 100%; animation-delay: .2s; } .eq i:nth-child(3) { height: 40%; animation-delay: .4s; }
  @keyframes eq { 0%,100% { transform: scaleY(.4); } 50% { transform: scaleY(1); } }
  .mp__item .dur { margin-left: auto; color: var(--muted); font-size: 11.5px; font-variant-numeric: tabular-nums; }
  .mp__foot { font-size: 10.5px; color: var(--muted); text-align: center; }
</style></head>
<body>
<div class="mp">
  <div class="mp__head"><div class="mp__title">♪ Music<small>Semitexa Ambient</small></div></div>
  <div class="mp__now">
    <div class="mp__track" id="track">—</div>
    <div class="mp__bar"><span id="cur">0:00</span><input class="mp__seek" id="seek" type="range" min="0" max="1000" value="0"><span id="dur">0:00</span></div>
    <div class="mp__ctl">
      <button class="mp__btn" id="prev" title="Previous">⏮</button>
      <button class="mp__btn mp__btn--play" id="play" title="Play/Pause">▶</button>
      <button class="mp__btn" id="next" title="Next">⏭</button>
      <input class="mp__vol" id="vol" type="range" min="0" max="100" value="80" title="Volume">
    </div>
  </div>
  <div class="mp__list" id="list"></div>
  <div class="mp__foot">Original generated tracks · no rights issues · loops the playlist</div>
</div>
<script type="application/json" id="mp-tracks">{$tracksJson}</script>
<script src="{$playerUrl}"></script>
</body></html>
HTML;

        return $resource
            ->setContent(CspNonce::stamp($html))
            ->setHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
