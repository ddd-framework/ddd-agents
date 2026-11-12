<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\MCPPackages\ToolResultImage;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The wire selection behind the tool-image cap: the unit is PICTURES (not results), newest results ride whole, the
 * newest result always rides, everything that does not ride is reported as dropped (its result gets the marker).
 */
class ToolCallImageWireSelectionTest extends UnitTestCase
{
    /** @return ToolResultImage[] */
    protected function pictures(int $count, string $tag): array
    {
        $pictures = [];
        for ($i = 0; $i < $count; $i++) {
            $picture = new ToolResultImage();
            $picture->sha256 = "$tag-$i";
            $pictures[] = $picture;
        }
        return $pictures;
    }

    public function testCountsPicturesNotResultsAndKeepsResultsWhole(): void
    {
        // newest first: logo call (3 pictures), then a sheet call (2), then an older sheet call (4)
        $selection = AgentModelInput::selectToolCallImagesForWire(
            ['logo' => $this->pictures(3, 'l'), 'sheets' => $this->pictures(2, 's'), 'older' => $this->pictures(4, 'o')],
            6
        );
        self::assertSame(['sheets', 'logo'], array_keys($selection->imagesOnWireByToolCallId), 'chronological order, the older sheet does not fit under 6 pictures');
        self::assertSame(['older'], $selection->droppedToolCallIds);
        self::assertCount(3, $selection->imagesOnWireByToolCallId['logo']);
    }

    public function testTheNewestResultAlwaysRidesTruncatedToTheCap(): void
    {
        $selection = AgentModelInput::selectToolCallImagesForWire(
            ['big' => $this->pictures(9, 'b'), 'small' => $this->pictures(1, 's')],
            4
        );
        self::assertSame(['big'], array_keys($selection->imagesOnWireByToolCallId));
        self::assertCount(4, $selection->imagesOnWireByToolCallId['big'], 'truncated to the cap');
        self::assertSame(['small'], $selection->droppedToolCallIds, 'the cap is spent — the older result is dropped, not silently omitted');
    }

    public function testEverythingFitsNothingDropped(): void
    {
        $selection = AgentModelInput::selectToolCallImagesForWire(
            ['b' => $this->pictures(2, 'b'), 'a' => $this->pictures(2, 'a')],
            8
        );
        self::assertSame(['a', 'b'], array_keys($selection->imagesOnWireByToolCallId));
        self::assertSame([], $selection->droppedToolCallIds);
    }
}
