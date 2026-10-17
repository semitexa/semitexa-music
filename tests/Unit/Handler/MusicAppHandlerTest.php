<?php

declare(strict_types=1);

namespace Semitexa\Music\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Music\Application\Handler\PayloadHandler\MusicAppHandler;
use Semitexa\Music\Application\Payload\Request\MusicAppPayload;

/**
 * The player page: its track list travels as a JSON data block and its logic
 * as a linked static script, so nothing executable is written inline.
 */
final class MusicAppHandlerTest extends TestCase
{
    private const STATIC_DIR = __DIR__ . '/../../../src/Application/Static';

    #[Test]
    public function the_track_list_is_a_data_block_pointing_at_bundled_files(): void
    {
        $tracks = $this->tracks($this->render());

        self::assertNotEmpty($tracks);
        foreach ($tracks as $track) {
            self::assertIsString($track['title'] ?? null);
            self::assertMatchesRegularExpression('#^/assets/music/audio/[a-z0-9-]+\.ogg\?v=#', $track['url'] ?? '');
            // A track the handler lists but the package does not ship would
            // 404 in the player with no other sign.
            $relative = (string) strtok(substr($track['url'], strlen('/assets/music/')), '?');
            self::assertFileExists(self::STATIC_DIR . '/' . $relative);
        }
    }

    #[Test]
    public function the_player_is_linked_not_inlined(): void
    {
        $html = $this->render();

        self::assertMatchesRegularExpression('#<script src="/assets/music/js/music-player\.js\?v=[^"]+"></script>#', $html);
        self::assertFileExists(self::STATIC_DIR . '/js/music-player.js');
        // Every <script> is either the data block or a linked file.
        preg_match_all('#<script([^>]*)>(.*?)</script>#s', $html, $m, PREG_SET_ORDER);
        foreach ($m as [, $attributes, $body]) {
            if (str_contains($attributes, 'application/json')) {
                continue;
            }
            self::assertStringContainsString('src=', $attributes);
            self::assertSame('', trim($body));
        }
    }

    #[Test]
    public function a_closing_script_tag_cannot_appear_inside_the_data_block(): void
    {
        preg_match('#<script type="application/json" id="mp-tracks">(.*?)</script>#s', $this->render(), $m);

        self::assertArrayHasKey(1, $m);
        self::assertStringNotContainsString('<', $m[1]);
    }

    private function render(): string
    {
        $resource = (new \ReflectionClass(ResourceResponse::class))->newInstanceWithoutConstructor();

        return (string) (new MusicAppHandler())->handle(new MusicAppPayload(), $resource)->getContent();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tracks(string $html): array
    {
        self::assertSame(1, preg_match('#<script type="application/json" id="mp-tracks">(.*?)</script>#s', $html, $m));
        $decoded = json_decode($m[1], true);
        self::assertIsArray($decoded);

        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
    }
}
