<?php

declare(strict_types=1);

namespace Tests\StaticPHP\Util;

use PHPUnit\Framework\TestCase;
use StaticPHP\Util\InteractiveTerm;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests for InteractiveTerm progress rendering, especially the elapsed
 * duration appended to finished progress cycles (RFC #964).
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

    public function testFinishAppendsElapsedDurationAfterIndicateProgress(): void
    {
        InteractiveTerm::indicateProgress('Building package: test');
        InteractiveTerm::finish('Built package: test');

        $this->assertMatchesRegularExpression('/Built package: test \(\s*\d+\.\ds\)/', $this->output->fetch());
    }

    public function testFinishWithoutProgressCycleDoesNotAppendDuration(): void
    {
        InteractiveTerm::indicateProgress('Building package: test');
        InteractiveTerm::finish('Built package: test');
        $this->output->fetch();

        InteractiveTerm::indicateProgress('Building package: second');
        InteractiveTerm::finish('Built package: second');
        InteractiveTerm::finish('No cycle here');

        $this->assertMatchesRegularExpression('/Built package: second \(\s*\d+\.\ds\)/', $this->output->fetch());
        $this->assertNull($this->getStaticProperty('startedAt'));
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

    public function testFailedFinishAlsoAppendsDuration(): void
    {
        InteractiveTerm::indicateProgress('Building package: broken');
        InteractiveTerm::finish('Building package failed: broken', false);

        $this->assertMatchesRegularExpression('/Building package failed: broken \(\s*\d+\.\ds\)/', $this->output->fetch());
    }

    private function resetProgressState(): void
    {
        foreach (['indicator', 'startedAt'] as $property) {
            $ref = new \ReflectionProperty(InteractiveTerm::class, $property);
            $ref->setValue(null, null);
        }
    }

    private function getStaticProperty(string $property): mixed
    {
        return new \ReflectionProperty(InteractiveTerm::class, $property)->getValue();
    }
}
