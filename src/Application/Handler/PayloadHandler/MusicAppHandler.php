<?php

declare(strict_types=1);

namespace Semitexa\Music\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Contract\TypedHandlerInterface;
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
        $tracksJson = json_encode($tracks, JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT);

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
<script>
const TRACKS = {$tracksJson};

// ---- follow the OS theme (dark / light / auto, resolved by the shell) ----
// The shell stamps data-skin-mode on the PARENT document's <html>; we mirror
// it and keep mirroring on every change. Same-origin only — guarded so a
// standalone open (no parent shell) just stays dark.
(function syncTheme() {
  try {
    const proot = window.parent && window.parent !== window ? window.parent.document.documentElement : null;
    if (!proot) return;
    const apply = () => {
      const mode = proot.getAttribute('data-skin-mode') === 'light' ? 'light' : 'dark';
      if (document.documentElement.getAttribute('data-skin-mode') !== mode) {
        document.documentElement.setAttribute('data-skin-mode', mode);
      }
    };
    apply();
    new MutationObserver(apply).observe(proot, { attributes: true, attributeFilter: ['data-skin-mode'] });
  } catch (e) { /* cross-origin or sandbox — keep the dark default */ }
})();

const audio = new Audio();
audio.volume = .8;
let cur = -1, seeking = false;
const el = id => document.getElementById(id);
const fmt = s => isFinite(s) ? Math.floor(s/60) + ':' + String(Math.floor(s%60)).padStart(2,'0') : '0:00';

// ---- process-registry producer: honest now-playing bar in Chill ----
function report(action, extra) {
  try {
    fetch('/os/process/report', { method:'POST', headers:{'Content-Type':'application/json'},
      body: JSON.stringify(Object.assign({ action, id:'music:player', source:'music', origin:'external' }, extra || {})) });
  } catch (e) {}
}
let lastRep = 0;
function repProgress(force) {
  if (cur < 0 || audio.paused || !isFinite(audio.duration)) return;
  const now = Date.now();
  if (!force && now - lastRep < 5000) return;
  lastRep = now;
  report('progress', { progress: Math.round(audio.currentTime / audio.duration * 100),
                       detail: fmt(audio.currentTime) + ' / ' + fmt(audio.duration) });
}

function renderList() {
  el('list').innerHTML = TRACKS.map((t, i) =>
    '<button class="mp__item' + (i === cur ? ' active' + (audio.paused ? '' : ' playing') : '') + '" data-i="' + i + '">'
    + '<span class="eq"><i></i><i></i><i></i></span><span>' + t.title + '</span><span class="dur" data-dur="' + i + '"></span></button>'
  ).join('');
}
function load(i, autoplay) {
  cur = (i + TRACKS.length) % TRACKS.length;
  audio.src = TRACKS[cur].url;
  el('track').textContent = TRACKS[cur].title;
  renderList();
  if (autoplay) audio.play();
}
el('list').addEventListener('click', e => { const b = e.target.closest('[data-i]'); if (b) load(+b.dataset.i, true); });
el('play').onclick = () => { if (cur < 0) load(0, true); else if (audio.paused) audio.play(); else audio.pause(); };
el('prev').onclick = () => load(cur - 1, cur >= 0 && !audio.paused);
el('next').onclick = () => load(cur + 1, cur >= 0 && !audio.paused);
el('vol').oninput = e => audio.volume = e.target.value / 100;
el('seek').oninput = () => { seeking = true; };
el('seek').onchange = e => { if (isFinite(audio.duration)) audio.currentTime = audio.duration * e.target.value / 1000; seeking = false; repProgress(true); };
audio.onplay = () => { el('play').textContent = '⏸'; renderList();
  report('begin', { title: '♪ ' + TRACKS[cur].title, detail: 'playing' }); repProgress(true); };
audio.onpause = () => { el('play').textContent = '▶'; renderList();
  if (audio.currentTime > 0 && !audio.ended) report('complete', { detail: 'paused at ' + fmt(audio.currentTime) }); };
audio.onended = () => load(cur + 1, true); // loop the playlist
audio.ontimeupdate = () => {
  if (!seeking && isFinite(audio.duration)) el('seek').value = Math.round(audio.currentTime / audio.duration * 1000);
  el('cur').textContent = fmt(audio.currentTime);
  el('dur').textContent = fmt(audio.duration);
  repProgress(false);
};
window.addEventListener('pagehide', () => { if (!audio.paused) report('complete', { detail: 'player closed' }); });
// prefetch durations for the list
TRACKS.forEach((t, i) => { const a = new Audio(); a.preload = 'metadata'; a.src = t.url;
  a.onloadedmetadata = () => { const d = document.querySelector('[data-dur="' + i + '"]'); if (d) d.textContent = fmt(a.duration); }; });
renderList();
</script>
</body></html>
HTML;

        return $resource
            ->setContent($html)
            ->setHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
