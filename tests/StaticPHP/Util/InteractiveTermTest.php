<?php

declare(strict_types=1);

namespace Tests\StaticPHP\Util;

use PHPUnit\Framework\TestCase;
use StaticPHP\Util\InteractiveTerm;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests for InteractiveTerm progress rendering.
 *
 * @internal
 */
class InteractiveTermTest extends TestCase
{
    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->output = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, false);
        InteractiveTerm::init($this->output);
        $this->resetProgressState();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->resetProgressState();
    }

    public function testColorsFollowOutputDecoration(): void
    {
        InteractiveTerm::notice('plain message');
        $this->assertStringNotContainsString("\033[", $this->output->fetch());

        $decorated = new BufferedOutput(BufferedOutput::VERBOSITY_NORMAL, true);
        InteractiveTerm::init($decorated);
        InteractiveTerm::notice('colored message');
        $this->assertStringContainsString("\033[", $decorated->fetch());
    }

    private function resetProgressState(): void
    {
        $ref = new \ReflectionProperty(InteractiveTerm::class, 'indicator');
        $ref->setValue(null, null);
    }
}
