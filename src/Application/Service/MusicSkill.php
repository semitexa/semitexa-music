<?php

declare(strict_types=1);

namespace Semitexa\Music\Application\Service;

use Semitexa\Llm\Attribute\AsAiSkill;
use Semitexa\Llm\Domain\Enum\AiArgumentPolicy;
use Semitexa\Llm\Domain\Enum\AiConfirmationMode;
use Semitexa\Llm\Domain\Enum\AiRiskLevel;

/**
 * The Music UI-skill: opens the OS music player as a dialog (entry route
 * `/os/app/music`) with the bundled "Semitexa Ambient" playlist — four
 * original, program-generated tracks (see tools/gen_tracks.py), so a fresh
 * install can play music with zero copyright exposure.
 *
 * A leisure skill for Chill mode; the planner routes "play some music"
 * (any language) here.
 */
#[AsAiSkill(
    name: 'music',
    summary: 'Open the music player with the built-in ambient playlist.',
    useWhen: 'The user wants to listen to music, play a song, put something on in the background — "play some music", "включи музику", "хочу послухати музику", "постав щось фонове".',
    avoidWhen: 'The user names a specific external service (YouTube, Spotify) or asks for a specific real-world artist/song — route those to the web-app opener instead.',
    riskLevel: AiRiskLevel::Low,
    confirmation: AiConfirmationMode::Never,
    argumentPolicy: AiArgumentPolicy::None,
    channels: ['ui'],
    icon: 'music',
    entry: '/os/app/music',
)]
final class MusicSkill
{
}
