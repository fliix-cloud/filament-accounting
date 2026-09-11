<?php

namespace FilamentAccounting\Tests\Banking\FinTs;

use Fhp\Model\TanRequest;
use FilamentAccounting\Banking\FinTs\Exceptions\ScaExpiredException;
use FilamentAccounting\Banking\FinTs\Support\SerializedFintsPayload;
use FilamentAccounting\Tests\Banking\FinTs\Fakes\FakeAction;
use FilamentAccounting\Tests\Banking\FinTs\Fakes\FakeTanRequest;
use FilamentAccounting\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SerializedFintsPayloadTest extends TestCase
{
    #[Test]
    public function non_fhp_objects_are_rejected(): void
    {
        $this->expectException(ScaExpiredException::class);
        SerializedFintsPayload::unserialize(serialize(new \ArrayObject(['tan' => '123456'])));
    }

    #[Test]
    public function scalar_and_array_payloads_are_allowed(): void
    {
        $this->assertSame(['ok' => 1], SerializedFintsPayload::unserialize(serialize(['ok' => 1])));
    }

    #[Test]
    public function php_unserialize_does_not_enable_all_classes(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/src/Banking/FinTs/Support/SerializedFintsPayload.php');

        $this->assertIsString($source);
        $this->assertStringNotContainsString("'allowed_classes' => true", $source);
        $this->assertStringContainsString("'allowed_classes' => \$classes === [] ? false : \$classes", $source);
    }

    #[Test]
    public function stored_action_graphs_may_include_tan_request_implementations(): void
    {
        $action = new FakeAction;
        $action->setTanRequest(new FakeTanRequest);

        $restored = SerializedFintsPayload::unserialize(serialize($action), requireObject: true);

        $this->assertInstanceOf(FakeAction::class, $restored);
        $this->assertInstanceOf(TanRequest::class, $restored->getTanRequest());
    }
}
