#!/usr/bin/env python3
"""Generative ambient tracks for the Semitexa OS default music player.

Everything is synthesized from scratch (sines, noise, envelopes) — the output
is original program-generated audio with no samples, no copyrighted material.
Deterministic (seeded) so the package tracks are reproducible.
"""
import math, os, sys, wave
import numpy as np

SR = 44100
OUT = sys.argv[1] if len(sys.argv) > 1 else "."
rng = np.random.default_rng(20260708)

def t(dur): return np.arange(int(dur * SR)) / SR

def env_ar(n, a, r):
    """attack/release envelope over n samples (times in seconds)."""
    e = np.ones(n)
    na, nr = int(a * SR), int(r * SR)
    if na > 0: e[:na] = np.linspace(0, 1, na)
    if nr > 0: e[-nr:] *= np.linspace(1, 0, nr)
    return e

def tone(freq, dur, partials=((1, 1.0), (2, .25), (3, .12), (4, .06)), detune=0.0, phase=0.0):
    x = t(dur)
    y = np.zeros_like(x)
    for mult, amp in partials:
        y += amp * np.sin(2 * math.pi * freq * mult * (1 + detune) * x + phase)
    return y

def pad(freq, dur, a=2.5, r=3.0, voices=3, spread=0.004):
    n = int(dur * SR)
    y = np.zeros(n)
    for v in range(voices):
        d = spread * (v - (voices - 1) / 2)
        y += tone(freq, dur, partials=((1, 1), (2, .35), (3, .1)), detune=d, phase=rng.uniform(0, 6.28))
    return y / voices * env_ar(n, a, r)

def pluck(freq, dur, bright=.5):
    n = int(dur * SR)
    x = t(dur)
    y = np.sin(2 * math.pi * freq * x) + bright * .5 * np.sin(2 * math.pi * freq * 2 * x) \
        + bright * .2 * np.sin(2 * math.pi * freq * 3 * x)
    return y * np.exp(-x * 3.2)

def lowpass(y, alpha):
    out = np.empty_like(y)
    acc = 0.0
    for i in range(len(y)):          # one-pole; fine at this scale
        acc += alpha * (y[i] - acc)
        out[i] = acc
    return out

def lowpass_fft(y, cutoff):
    f = np.fft.rfft(y)
    freqs = np.fft.rfftfreq(len(y), 1 / SR)
    f *= 1 / (1 + (freqs / cutoff) ** 2)
    return np.fft.irfft(f, len(y))

def delay(y, time_s, fb=.45, mix=.35):
    d = int(time_s * SR)
    out = y.copy()
    buf = y.copy()
    for _ in range(6):
        buf = np.concatenate([np.zeros(d), buf[:-d]]) * fb
        out += buf * mix
    return out

def place(buf, y, at, gain=1.0):
    i = int(at * SR)
    j = min(len(buf), i + len(y))
    if i < len(buf):
        buf[i:j] += y[:j - i] * gain

def stereo(mono_l, mono_r, master=.85):
    m = max(np.max(np.abs(mono_l)), np.max(np.abs(mono_r)), 1e-9)
    l, r = mono_l / m * master, mono_r / m * master
    n = len(l)
    fade = env_ar(n, 1.5, 3.0)
    return l * fade, r * fade

def write_wav(name, l, r):
    data = np.empty(len(l) * 2, dtype=np.int16)
    data[0::2] = np.clip(l * 32767, -32767, 32767).astype(np.int16)
    data[1::2] = np.clip(r * 32767, -32767, 32767).astype(np.int16)
    with wave.open(os.path.join(OUT, name), "wb") as w:
        w.setnchannels(2); w.setsampwidth(2); w.setframerate(SR)
        w.writeframes(data.tobytes())
    print("wrote", name)

NOTE = {n: 440 * 2 ** ((i - 9) / 12) for i, n in enumerate(
    ["C", "C#", "D", "D#", "E", "F", "F#", "G", "G#", "A", "A#", "B"])}
def hz(name, octave): return NOTE[name] * 2 ** (octave - 4)

# ---- 1. Midnight Navy — slow warm chords over a soft sub ----
def midnight_navy(dur=130):
    n = int(dur * SR)
    L, R = np.zeros(n), np.zeros(n)
    chords = [  # Am9 · Fmaj7 · Cmaj7 · G6
        [("A", 2), ("E", 3), ("G", 3), ("B", 3), ("C", 4)],
        [("F", 2), ("C", 3), ("E", 3), ("A", 3)],
        [("C", 3), ("E", 3), ("G", 3), ("B", 3)],
        [("G", 2), ("D", 3), ("G", 3), ("B", 3), ("E", 4)],
    ]
    hold = 8.0
    at = 0.0
    k = 0
    while at < dur - hold:
        notes = chords[k % 4]
        for (nm, octv) in notes:
            y = pad(hz(nm, octv), hold + 2, a=2.8, r=3.5)
            place(L, y, at, .5 + .1 * rng.random())
            place(R, y, at, .5 + .1 * rng.random())
        sub = tone(hz(notes[0][0], 1), hold + 2, partials=((1, 1),)) * env_ar(int((hold + 2) * SR), 3, 4)
        place(L, sub, at, .35); place(R, sub, at, .35)
        at += hold; k += 1
    L, R = lowpass_fft(L, 2400), lowpass_fft(R, 2400)
    return stereo(delay(L, .42), delay(R, .53))

# ---- 2. Cyan Drift — dorian drone + sparse bell random-walk ----
def cyan_drift(dur=128):
    n = int(dur * SR)
    L, R = np.zeros(n), np.zeros(n)
    for f, g in [(hz("D", 2), .5), (hz("A", 2), .35), (hz("D", 3), .22)]:
        y = pad(f, dur, a=6, r=8, voices=4)
        place(L, y, 0, g); place(R, y, 0, g * .95)
    scale = [hz(x, o) for o in (4, 5) for x in ("D", "E", "F", "A", "C")]
    at, idx = 6.0, 4
    while at < dur - 8:
        idx = max(0, min(len(scale) - 1, idx + rng.integers(-2, 3)))
        y = pluck(scale[idx], 4.0, bright=.6)
        pan = rng.uniform(.25, .75)
        place(L, y, at, .5 * (1 - pan) + .15)
        place(R, y, at, .5 * pan + .15)
        at += float(rng.uniform(2.2, 4.8))
    L, R = lowpass_fft(L, 3200), lowpass_fft(R, 3200)
    return stereo(delay(L, .61, fb=.5), delay(R, .74, fb=.5))

# ---- 3. Low Orbit — deep slow pulse + airy fifth + noise swells ----
def low_orbit(dur=126):
    n = int(dur * SR)
    L, R = np.zeros(n), np.zeros(n)
    at = 0.0
    while at < dur - 3:
        y = tone(hz("C", 2), 2.4, partials=((1, 1), (2, .15))) * np.exp(-t(2.4) * 1.8)
        place(L, y, at, .5); place(R, y, at + .012, .5)   # tiny haas offset
        at += 2.0
    for f, g in [(hz("C", 4), .16), (hz("G", 4), .12), (hz("C", 5), .07)]:
        place(L, pad(f, dur, a=8, r=10, voices=4), 0, g)
        place(R, pad(f * 1.001, dur, a=8, r=10, voices=4), 0, g)
    for s in range(4):
        start = 20 + s * 26
        noise = rng.standard_normal(int(10 * SR)) * .12
        noise = lowpass_fft(noise, 900) * env_ar(int(10 * SR), 5, 5)
        place(L, noise, start, .8); place(R, noise, start + .05, .8)
    return stereo(L, R)

# ---- 4. Warm Circuit — lofi chords, vinyl crackle, soft pulse ----
def warm_circuit(dur=132):
    n = int(dur * SR)
    L, R = np.zeros(n), np.zeros(n)
    chords = [  # Fmaj7 · Dm7 · Am7 · Em7
        [("F", 2), ("A", 3), ("C", 4), ("E", 4)],
        [("D", 2), ("F", 3), ("A", 3), ("C", 4)],
        [("A", 2), ("C", 3), ("E", 3), ("G", 3)],
        [("E", 2), ("G", 3), ("B", 3), ("D", 4)],
    ]
    beat = 60 / 72
    bar = beat * 4
    at, k = 0.0, 0
    while at < dur - bar:
        for (nm, octv) in chords[k % 4]:
            x = t(bar * 1.1)
            trem = 1 + .12 * np.sin(2 * math.pi * 4.3 * x)
            y = tone(hz(nm, octv), bar * 1.1, partials=((1, 1), (2, .3), (4, .05))) * trem \
                * env_ar(len(x), .04, bar * .5)
            place(L, y, at, .4); place(R, y, at + .008, .4)
        at += bar; k += 1
    # soft kick pulse
    at = 0.0
    while at < dur - 1:
        x = t(.35)
        kick = np.sin(2 * math.pi * (52 + 40 * np.exp(-x * 18)) * x) * np.exp(-x * 9)
        place(L, kick, at, .5); place(R, kick, at, .5)
        at += beat * 2
    # vinyl bed: filtered noise + sparse crackle ticks
    bed = lowpass_fft(rng.standard_normal(n) * .015, 3000)
    ticks = np.zeros(n)
    for _ in range(int(dur * 2.2)):
        i = rng.integers(0, n - 40)
        ticks[i:i + 40] += np.exp(-np.arange(40) / 6) * rng.uniform(.04, .12) * rng.choice([-1, 1])
    L += bed + ticks; R += bed * .9 + np.roll(ticks, 300)
    L, R = lowpass_fft(L, 2600), lowpass_fft(R, 2600)
    return stereo(L, R)

for name, fn in [("midnight-navy", midnight_navy), ("cyan-drift", cyan_drift),
                 ("low-orbit", low_orbit), ("warm-circuit", warm_circuit)]:
    l, r = fn()
    write_wav(name + ".wav", l, r)
