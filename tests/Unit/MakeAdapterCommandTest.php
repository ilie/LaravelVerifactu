<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Support\Facades\Artisan;

class MakeAdapterCommandTest extends TestCase
{
    public function testCommandIsRegistered(): void
    {
        $commands = Artisan::all();
        $this->assertArrayHasKey('verifactu:make-adapter', $commands);
    }

    public function testCommandGeneratesStub(): void
    {
        // We can't easily test the full file generation without mocking filesystem
        // or creating temporary files, but we can verify the command runs
        // and outputs the expected instructions.

        $this->artisan('verifactu:make-adapter', ['model' => 'TestInvoice'])
            ->expectsOutput('Add the following methods to your TestInvoice model:')
            ->expectsOutput("Don't forget to add imports:")
            ->assertExitCode(0);
    }
}
