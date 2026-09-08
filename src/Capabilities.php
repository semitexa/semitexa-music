<?php

declare(strict_types=1);

namespace Semitexa\Music;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * The package ships no attributes of its own, so there is nothing for a
 * mechanism-level declaration to hang on — and without this the package is
 * invisible to anyone whose project has not installed it, which is precisely
 * the audience worth telling. The convention is one `Capabilities` class per
 * package: a definite place to look, and a definite place for a guard to check.
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'os.music',
    summary: 'The OS music player: a Music UI-skill with a bundled playlist of original, program-generated ambient tracks.',
    useWhen: 'The environment should be able to play something without sending the user to another tab, and without licensing audio first.',
    avoidWhen: 'A conventional application. A media player nobody asked for is a distraction with an audio element attached.',
    replaces: [
        'an <audio> element wired into a bespoke route with its own playback state',
        'sourcing and clearing background tracks before the feature can ship at all',
    ],
)]
final class Capabilities
{
}
