<?php

declare(strict_types=1);

namespace Semitexa\Music\Application\Payload\Request;

use Semitexa\Authorization\Attribute\AsProtectedPayload;
use Semitexa\Os\Domain\Contract\OsSurfacePayloadInterface;
use Semitexa\Core\Http\Response\ResourceResponse;

/** The music player page, embedded as an OS dialog (Music UI-skill entry). */
/**
 * Console surface: gated by OsAdminGate, not merely by being signed in.
 *
 * This window mounts under /os/app, so a visitor authenticated by the host
 * site's own login would satisfy #[AsProtectedPayload] exactly as an operator
 * does. OsSurfacePayloadInterface is what asks the narrower question.
 */
#[AsProtectedPayload(
    path: '/os/app/music',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class MusicAppPayload implements OsSurfacePayloadInterface
{
}
