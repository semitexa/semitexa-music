// Music player for the OS dialog. Served as a static asset rather than a
// string inside MusicAppHandler, so linters and tests can reach it. The track
// list is server state (fingerprinted URLs) and arrives as a JSON data block.
const TRACKS = JSON.parse(document.getElementById('mp-tracks').textContent);

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
// Prefetched durations by track index: renderList() rebuilds every row, and a
// metadata callback that already fired would not refill the new ones.
const durs = [];
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
    + '<span class="eq"><i></i><i></i><i></i></span><span>' + t.title + '</span><span class="dur" data-dur="' + i + '">' + (durs[i] === undefined ? '' : fmt(durs[i])) + '</span></button>'
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
  a.onloadedmetadata = () => { durs[i] = a.duration; const d = el('list').querySelector('[data-dur="' + i + '"]'); if (d) d.textContent = fmt(a.duration); }; });
renderList();
