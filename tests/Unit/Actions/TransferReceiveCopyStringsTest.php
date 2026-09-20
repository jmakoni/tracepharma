<?php

declare(strict_types=1);

namespace Tests\Unit\Actions;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TransferReceiveCopyStringsTest extends TestCase
{
    #[Test]
    public function transfer_receive_action_messages_name_the_transfer(): void
    {
        $path = dirname(__DIR__, 3).'/app/Actions/Transferring/ConfirmTransferringReceiveScan.php';
        $source = file_get_contents($path);

        $this->assertIsString($source);
        $this->assertStringContainsString("'Already received on this transfer'", $source);
        $this->assertStringContainsString("'Transfer receive complete'", $source);
        $this->assertStringNotContainsString("'Transfer already received.'", $source);
        $this->assertStringNotContainsString("'Received — transfer complete.'", $source);
    }
}
