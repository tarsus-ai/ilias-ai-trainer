<?php

declare(strict_types=1);

namespace TarsusIlias\Tests\Support;

use PHPUnit\Framework\TestCase;
use TarsusIlias\Support\Budget;

/**
 * Pricing the month: interactions beyond the included amount, per
 * interaction, at the agreed price - and nothing for a customer Tarsus
 * bills directly.
 */
final class BudgetTest extends TestCase
{
    public function testNothingIsChargedWithinTheIncludedAmount(): void
    {
        $overage = Budget::overageOf(99999, 4000, 100000, 0.32);
        $this->assertSame(0, $overage['charged']);
        $this->assertSame(0.0, $overage['cost']);
        $this->assertSame(4000, $overage['requests']);
        $this->assertSame(99999, $overage['used']);
    }

    public function testInteractionsBeyondTheIncludedAmountArePricedPerInteraction(): void
    {
        $overage = Budget::overageOf(100250, 60000, 100000, 0.32);
        $this->assertSame(250, $overage['charged'], 'KI-Interaktionen über die 100.000 im Monat hinaus');
        $this->assertSame(80.0, $overage['cost'], '250 × 0,32 €');
    }

    public function testPayAsYouGrowChargesEveryInteraction(): void
    {
        $overage = Budget::overageOf(1234, 1200, 0, 0.32);
        $this->assertSame(1234, $overage['charged']);
        $this->assertSame(394.88, $overage['cost']);
    }

    public function testACustomPriceAndAmountAreHonoured(): void
    {
        $overage = Budget::overageOf(600, 600, 500, 0.25);
        $this->assertSame(100, $overage['charged']);
        $this->assertSame(25.0, $overage['cost']);
        $this->assertSame(500, $overage['included']);
    }

    public function testALicenseKeyCustomerSeesNoPartnerCost(): void
    {
        $overage = Budget::overageOf(100250, 60000, 100000, 0.32, true);
        $this->assertSame(250, $overage['charged'], 'the figure is still shown');
        $this->assertSame(0.0, $overage['cost'], 'but Tarsus bills it, not the partner');
    }

    public function testNegativeFiguresAreTreatedAsZero(): void
    {
        $overage = Budget::overageOf(10, 10, -5, 0.32);
        $this->assertSame(0, $overage['included']);
        $this->assertSame(10, $overage['charged']);
    }
}
